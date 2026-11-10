<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class FinancialAnalyticsService
{
    public function __construct(private AdminDestinationScopeService $scope) {}

    public function summary(User $actor, array $filters = []): array
    {
        $actor = $this->scope->actor($actor);
        if (isset($filters['destination_guid'])) {
            $this->scope->authorize($actor, $filters['destination_guid']);
        }
        $environment = $filters['environment'] ?? 'production';
        abort_unless(in_array($environment, ['sandbox', 'demo', 'production'], true), 422);
        $provider = $environment === 'demo' ? 'demo' : 'doku';
        $period = ['from' => $filters['from'] ?? now()->utc()->startOfMonth()->toDateString(),
            'to' => $filters['to'] ?? now()->utc()->toDateString(), 'date_basis' => 'paid_utc', 'timezone' => 'UTC'];
        $base = $this->attempts($actor, $filters, $environment);
        $captures = $this->captures(clone $base);
        $providers = (clone $captures)->distinct()->limit(3)->pluck('a.provider');
        $provider = $providers->count() > 1 ? 'mixed' : ($providers->first() ?? $provider);
        $data = ['availability' => 'unavailable', 'reason' => 'no_verified_payment_source',
            'environment' => $environment, 'provider' => $provider, 'test_only' => $environment !== 'production',
            'label' => $environment === 'demo' ? 'DEMO' : strtoupper($environment), 'currency' => 'IDR',
            'source' => 'unavailable', 'date_basis' => 'paid_utc', 'refund_date_basis' => 'refund_utc', 'period' => $period,
            'scope' => ['mode' => (int) $actor->role === 2 ? 'global' : 'assigned_destinations', 'destination_guid' => $filters['destination_guid'] ?? null],
            'gross_revenue' => null, 'gross_collected' => null, 'unapplied_collected' => null,
            'net_revenue' => null, 'gateway_fees' => null, 'refunds' => null,
            'refunds_basis' => 'verified_receipts', 'refunds_complete' => false, 'settlement' => null,
            'successful_transactions' => null, 'successful_payment_attempts' => null, 'tickets_sold' => null,
            'payment_attempts' => null, 'failed_attempts' => null, 'expired_attempts' => null, 'unknown_attempts' => null,
            'attempt_conversion' => null, 'booking_conversion' => null, 'method_mix' => null,
            'method_mix_limit' => 100, 'method_mix_truncated' => null, 'avg_payment_seconds' => null,
            'missing_fields' => ['verified_capture_source', 'gateway_fees', 'refunds']];
        if (! (clone $captures)->exists() && ! ($environment === 'demo' && app(PaymentGateway::class)->demoEnabled())) {
            return $data;
        }

        $recognized = $this->recognitionSql();
        $seconds = DB::getDriverName() === 'sqlite'
            ? '(julianday(a.verified_paid_at) - julianday(a.started_at)) * 86400'
            : 'TIMESTAMPDIFF(SECOND, a.started_at, a.verified_paid_at)';
        $money = $this->between(clone $captures, 'a.verified_paid_at', $period)
            ->selectRaw("COUNT(*) as captures, COALESCE(SUM(a.amount),0) as collected, COALESCE(SUM(CASE WHEN $recognized THEN a.amount ELSE 0 END),0) as recognized, COUNT(CASE WHEN $recognized THEN 1 END) as transactions, COALESCE(SUM(CASE WHEN $recognized THEN b.quantity ELSE 0 END),0) as tickets, AVG(CASE WHEN a.verified_paid_at >= a.started_at THEN $seconds END) as avg_seconds")->first();
        $attempts = $this->between(clone $base, 'a.started_at', $period)
            ->selectRaw("COUNT(*) as total, COUNT(CASE WHEN a.status='failed' THEN 1 END) as failed, COUNT(CASE WHEN a.status='expired' THEN 1 END) as expired, COUNT(CASE WHEN a.status='unknown' THEN 1 END) as unknown_count")->first();
        $successfulAttempts = $this->between(clone $captures, 'a.started_at', $period)->whereIn('a.status', ['paid', 'reversed'])->count();
        $completedAttempts = $successfulAttempts + (int) $attempts->failed + (int) $attempts->expired;
        $eligible = $this->scope->apply(DB::table('bookings as b')->join('destination_quotas as q', 'q.guid', '=', 'b.destination_quota_guid'), $actor, 'q.destination_guid')
            ->where('b.total_amount', '>', 0)->where('b.currency', 'IDR')
            ->whereExists(fn (Builder $intent) => $intent->selectRaw('1')->from('payment_intents as eligible_intent')
                ->whereColumn('eligible_intent.booking_guid', 'b.guid')->whereIn('eligible_intent.provider', $environment === 'demo' ? ['demo'] : ['doku', 'midtrans'])->where('eligible_intent.environment', $environment))
            ->whereExists(fn (Builder $history) => $history->selectRaw('1')->from('booking_status_histories as h')
                ->whereColumn('h.booking_guid', 'b.guid')->where('h.new_status', 'awaiting_payment'));
        if (isset($filters['destination_guid'])) {
            $eligible->where('q.destination_guid', $filters['destination_guid']);
        }
        $eligibleBookings = $this->between($eligible, 'b.created_at', $period)->count();
        $paidBookings = $this->between(clone $captures, 'b.created_at', $period)->whereRaw($recognized)->count();
        $mix = $this->between(clone $captures, 'a.verified_paid_at', $period)
            ->selectRaw('a.method, a.channel, COUNT(*) as successful_attempts, SUM(a.amount) as gross_collected')
            ->groupBy('a.method', 'a.channel')->orderBy('a.method')->orderBy('a.channel')->limit(101)->get();
        $mixTruncated = $mix->count() > 100;
        $mix = $mix->take(100)
            ->map(fn (object $row): array => ['method' => $row->method, 'channel' => $row->channel,
                'successful_attempts' => (int) $row->successful_attempts, 'gross_collected' => (int) $row->gross_collected])->all();
        $refundEvents = app(PaymentEligibility::class)->boundEvents('refund', fullAmount: false)
            ->whereIn('e.disposition', ['applied', 'duplicate_adjustment'])->whereColumn('e.guid', 'refund.guid')
            ->whereColumn('e.provider_occurred_at', '>=', 'a.verified_paid_at');
        $earlierFullRefund = app(PaymentEligibility::class)->boundEvents('refund')
            ->whereIn('e.disposition', ['applied', 'duplicate_adjustment'])->whereColumn('e.provider_occurred_at', '>=', 'a.verified_paid_at')
            ->where(fn (Builder $earlier) => $earlier->whereColumn('e.provider_occurred_at', '<', 'refund.provider_occurred_at')
                ->orWhere(fn (Builder $tie) => $tie->whereColumn('e.provider_occurred_at', 'refund.provider_occurred_at')->whereColumn('e.guid', '<', 'refund.guid')));
        $refunds = $this->between((clone $captures)->join('payment_events as refund', 'refund.payment_attempt_guid', '=', 'a.guid')
            ->whereExists($refundEvents)->where(fn (Builder $receipt) => $receipt->whereColumn('refund.amount', '<', 'a.amount')
            ->orWhereNotExists($earlierFullRefund)), 'refund.provider_occurred_at', $period)
            ->selectRaw('COUNT(*) as receipts, SUM(refund.amount) as amount')->first();

        return array_replace($data, ['availability' => 'partial', 'reason' => $refunds->receipts ? 'fees_unavailable_refunds_incomplete' : 'fees_and_refunds_unavailable',
            'source' => $environment === 'demo' ? 'simulated_demo_events' : 'verified_'.$environment.'_events', 'gross_revenue' => (int) $money->recognized,
            'gross_collected' => (int) $money->collected, 'unapplied_collected' => (int) $money->collected - (int) $money->recognized,
            'successful_transactions' => (int) $money->transactions, 'successful_payment_attempts' => (int) $money->captures,
            'tickets_sold' => (int) $money->tickets, 'payment_attempts' => (int) $attempts->total,
            'failed_attempts' => (int) $attempts->failed, 'expired_attempts' => (int) $attempts->expired, 'unknown_attempts' => (int) $attempts->unknown_count,
            'refunds' => $refunds->receipts ? (int) $refunds->amount : null,
            'attempt_conversion' => ['successful_attempts' => $successfulAttempts, 'completed_attempts' => $completedAttempts,
                'percent' => $completedAttempts ? round($successfulAttempts / $completedAttempts * 100, 2) : null, 'date_basis' => 'attempt_started_utc'],
            'booking_conversion' => ['paid_bookings' => $paidBookings, 'payment_eligible_bookings' => $eligibleBookings,
                'percent' => $eligibleBookings ? round($paidBookings / $eligibleBookings * 100, 2) : null, 'date_basis' => 'booking_created_utc',
                'environment' => $environment, 'denominator_scope' => 'approved_payable_bookings_with_environment_intent'],
            'method_mix' => $mix, 'method_mix_truncated' => $mixTruncated,
            'avg_payment_seconds' => $money->avg_seconds === null ? null : round((float) $money->avg_seconds, 2),
            'missing_fields' => $refunds->receipts ? ['gateway_fees', 'refund_completeness'] : ['gateway_fees', 'refunds']]);
    }

    private function attempts(User $actor, array $filters, string $environment): Builder
    {
        $query = DB::table('payment_attempts as a')->join('payment_intents as i', 'i.guid', '=', 'a.payment_intent_guid')
            ->join('bookings as b', 'b.guid', '=', 'i.booking_guid')->join('destination_quotas as q', 'q.guid', '=', 'b.destination_quota_guid')
            ->whereIn('a.provider', $environment === 'demo' ? ['demo'] : ['doku', 'midtrans'])->whereColumn('i.provider', 'a.provider')
            ->where('a.environment', $environment)->where('i.environment', $environment)
            ->where('a.currency', 'IDR')->whereColumn('i.currency', 'a.currency')->whereColumn('b.currency', 'a.currency')
            ->where('a.amount', '>', 0)->whereColumn('a.amount', 'i.amount')->whereColumn('i.amount', 'b.total_amount');
        $this->scope->apply($query, $actor, 'q.destination_guid');
        if (isset($filters['destination_guid'])) {
            $query->where('q.destination_guid', $filters['destination_guid']);
        }

        return $query;
    }

    private function captures(Builder $query): Builder
    {
        return $query->whereNotNull('a.verified_paid_at')->whereColumn('a.verified_paid_at', '>=', 'a.started_at')
            ->whereExists(app(PaymentEligibility::class)->boundEvents('capture')
                ->whereColumn('e.provider_occurred_at', 'a.verified_paid_at')->whereIn('e.disposition', ['applied', 'unapplied', 'duplicate_capture']));
    }

    private function recognitionSql(): string
    {
        return <<<'SQL'
            i.applied_attempt_guid=a.guid AND i.status='paid' AND i.verified_paid_at=a.verified_paid_at
            AND a.status='paid' AND a.reversed_at IS NULL AND b.status='confirmed'
            AND EXISTS (SELECT 1 FROM booking_status_histories approval
                WHERE approval.booking_guid=b.guid AND approval.new_status='awaiting_payment')
            AND EXISTS (SELECT 1 FROM payment_events applied
                WHERE applied.payment_attempt_guid=a.guid AND applied.payment_intent_guid=i.guid
                AND applied.provider=a.provider AND applied.environment=a.environment
                AND applied.verification='verified' AND applied.event_type='capture' AND applied.disposition='applied'
                AND applied.amount=a.amount AND applied.currency=a.currency AND applied.provider_occurred_at=a.verified_paid_at)
            AND NOT EXISTS (SELECT 1 FROM payment_events adjustment
                WHERE adjustment.payment_attempt_guid=a.guid AND adjustment.payment_intent_guid=i.guid
                AND adjustment.provider=a.provider AND adjustment.environment=a.environment AND adjustment.currency=a.currency
                AND adjustment.verification='verified' AND adjustment.event_type='reversal' AND adjustment.disposition='applied'
                AND adjustment.amount>0 AND adjustment.provider_occurred_at IS NOT NULL)
            SQL;
    }

    private function between(Builder $query, string $column, array $period): Builder
    {
        return $query->where($column, '>=', $period['from'].' 00:00:00')
            ->where($column, '<', CarbonImmutable::parse($period['to'], 'UTC')->addDay()->toDateTimeString());
    }
}
