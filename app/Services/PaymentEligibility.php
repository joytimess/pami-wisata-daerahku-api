<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PaymentEligibility
{
    /** Historical status depends only on the original verified ledger, never the active gateway. */
    public function status(object $booking): string
    {
        if (isset($booking->has_payment) && ! $booking->has_payment) {
            return 'unverified';
        }
        $base = DB::table('payment_intents as i')
            ->join('payment_attempts as a', 'a.guid', '=', 'i.applied_attempt_guid')
            ->join('bookings as b', 'b.guid', '=', 'i.booking_guid')
            ->where('b.guid', $booking->guid)->where('b.total_amount', '>', 0)
            ->where(fn (Builder $provider) => $provider
                ->where(fn (Builder $real) => $real->whereIn('i.provider', ['doku', 'midtrans'])->whereIn('i.environment', ['sandbox', 'production'])->whereNotNull('a.provider_reference'))
                ->orWhere(fn (Builder $demo) => $demo->where('i.provider', 'demo')->where('i.environment', 'demo')))
            ->whereColumn('a.provider', 'i.provider')->whereColumn('a.environment', 'i.environment')
            ->whereColumn('a.payment_intent_guid', 'i.guid')->whereColumn('a.amount', 'i.amount')
            ->whereColumn('i.amount', 'b.total_amount')->where('i.currency', 'IDR')
            ->whereColumn('a.currency', 'i.currency')->whereColumn('b.currency', 'i.currency')
            ->whereColumn('i.verified_paid_at', 'a.verified_paid_at')->whereColumn('i.expires_at', 'b.payment_expires_at')
            ->whereColumn('a.verified_paid_at', '>=', 'a.started_at')
            ->whereColumn('a.verified_paid_at', '<', 'b.payment_expires_at')
            ->whereColumn('a.verified_paid_at', '<', 'a.instruction_expires_at')
            ->whereExists(fn (Builder $history) => $history->selectRaw('1')->from('booking_status_histories as h')
                ->whereColumn('h.booking_guid', 'b.guid')->where('h.new_status', 'awaiting_payment'))
            ->whereExists($this->boundEvents('capture')->where('e.disposition', 'applied')->whereColumn('e.provider_occurred_at', 'a.verified_paid_at')
                ->where(fn (Builder $source) => $source->where('i.provider', '!=', 'demo')->orWhere('e.metadata->source', 'demo_simulator')));
        $adjustments = $this->boundEvents()->whereIn('e.event_type', ['refund', 'reversal'])->where('e.disposition', 'applied')
            ->whereColumn('e.provider_occurred_at', '>=', 'a.verified_paid_at');
        if ((clone $base)->where('b.status', 'confirmed')->where('i.status', 'paid')->where('a.status', 'paid')
            ->whereNull('a.reversed_at')->whereNotExists(clone $adjustments)->exists()) {
            return 'paid';
        }
        $reversed = $base->where('a.status', 'reversed')->whereNotNull('a.reversed_at')
            ->whereExists($adjustments->whereColumn('e.provider_occurred_at', 'a.reversed_at'))->exists();

        return $reversed ? 'reversed' : 'unverified';
    }

    /** Full original ledger binding. Missing legacy outcome metadata is allowed; a supplied snapshot must agree. */
    public function boundEvents(?string $type = null, bool $fullAmount = true): Builder
    {
        $query = DB::table('payment_events as e')->selectRaw('1')
            ->whereColumn('e.payment_intent_guid', 'i.guid')->whereColumn('e.payment_attempt_guid', 'a.guid')
            ->whereColumn('e.provider', 'i.provider')->whereColumn('e.environment', 'i.environment')
            ->where('e.amount', '>', 0)->whereColumn('e.amount', $fullAmount ? '=' : '<=', 'a.amount')->whereColumn('e.currency', 'a.currency')
            ->where('e.verification', 'verified')->whereNotNull('e.provider_occurred_at')
            ->whereColumn('e.provider_occurred_at', '>=', 'a.started_at');
        if ($type !== null) {
            $query->where('e.event_type', $type);
        }
        // An absent key is legacy-compatible; a present key (including JSON null) must equal the bound column on every driver.
        foreach (['merchant_reference' => 'a', 'provider_reference' => 'a', 'method' => 'a', 'channel' => 'a', 'amount' => 'e', 'currency' => 'a',
            'provider' => 'e', 'environment' => 'e'] as $field => $table) {
            $query->where(fn (Builder $snapshot) => $snapshot->whereJsonDoesntContainKey('e.metadata->outcome->'.$field)
                ->orWhereColumn('e.metadata->outcome->'.$field, $table.'.'.$field));
        }
        $timestamp = $query->getGrammar()->wrap('e.metadata->outcome->occurred_at');
        if (DB::getDriverName() === 'sqlite') {
            // Ledger timestamps have whole-second precision; julianday alone rounds sub-millisecond contradictions away.
            $timeMatches = "$timestamp GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9][T ][0-9][0-9]:[0-9][0-9]:[0-9][0-9]*'"
                ." AND date(substr($timestamp, 1, 10), '+0 days') = substr($timestamp, 1, 10)"
                ." AND (substr($timestamp, 20, 1) != '.' OR CAST(substr($timestamp, 20) AS REAL) = 0)"
                ." AND julianday($timestamp) = julianday(e.provider_occurred_at)";
            $timeBindings = [];
        } else {
            $offsetTime = "REPLACE($timestamp, 'Z', '+00:00')";
            $timeMatches = "$timestamp REGEXP ? AND CONVERT_TZ(LEFT($offsetTime, CHAR_LENGTH($offsetTime) - 6), RIGHT($offsetTime, 6), '+00:00') = e.provider_occurred_at";
            $timeBindings = ['^[0-9]{4}-[0-9]{2}-[0-9]{2}[T ][0-9]{2}:[0-9]{2}:[0-9]{2}([.][0-9]{1,6})?([+-][0-9]{2}:[0-9]{2}|Z)$'];
        }
        $query->where(fn (Builder $snapshot) => $snapshot->whereJsonDoesntContainKey('e.metadata->outcome->occurred_at')
            ->orWhereRaw('('.$timeMatches.')', $timeBindings));
        $query->where(fn (Builder $status) => $status->whereNull('e.metadata->outcome->status')
            ->orWhere(fn (Builder $capture) => $capture->where('e.event_type', 'capture')->where('e.metadata->outcome->status', 'paid'))
            ->orWhere(fn (Builder $refund) => $refund->where('e.event_type', 'refund')->where('e.metadata->outcome->status', 'refunded'))
            ->orWhere(fn (Builder $reversal) => $reversal->where('e.event_type', 'reversal')->where('e.metadata->outcome->status', 'reversed')));

        return $query;
    }

    /**
     * Admission entitlement, the single question "may this booking hold entry QR units": a verified payment (`paid`), an explicit
     * free entitlement (`free`), a settled refund (`refunded`) or the unchanged payment status. `free` is never derived from any
     * payment record: it requires an approved, confirmed Rp0 booking that has no payment intent at all.
     */
    public function entitlement(object $booking): string
    {
        if (DB::table('booking_refund_requests')->where('booking_guid', $booking->guid)->whereIn('status', ['refunded', 'manual_refunded'])->exists()) {
            return 'refunded';
        }
        $status = $this->status($booking);
        if ($status === 'paid') {
            $provenance = $this->provenance($booking);

            if (($provenance['payment_provider'] === 'demo' && ! app(PaymentGateway::class)->demoEnabled())
                || (app()->environment('production') && $provenance['payment_environment'] !== 'production')) {
                return 'unverified';
            }

            return 'paid';
        }

        return $status === 'unverified' && $this->freeEntitlement($booking) ? 'free' : $status;
    }

    private function freeEntitlement(object $booking): bool
    {
        return $booking->status === 'confirmed' && (int) $booking->total_amount === 0 && $booking->currency === 'IDR' && (int) $booking->quantity > 0
            && ! DB::table('payment_intents')->where('booking_guid', $booking->guid)->exists()
            && DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('old_status', 'pending_approval')
                ->where('new_status', 'confirmed')->whereNotNull('actor_guid')->exists();
    }

    /** @return array{payment_provider: ?string, payment_environment: ?string, payment_test_only: bool, payment_label: ?string} */
    public function provenance(object $booking): array
    {
        $intent = isset($booking->has_payment) && ! $booking->has_payment ? null
            : DB::table('payment_intents')->where('booking_guid', $booking->guid)->first(['provider', 'environment']);
        $valid = $intent && (($intent->provider === 'demo' && $intent->environment === 'demo')
            || (in_array($intent->provider, ['doku', 'midtrans'], true) && in_array($intent->environment, ['sandbox', 'production'], true)));

        return ['payment_provider' => $valid ? $intent->provider : null, 'payment_environment' => $valid ? $intent->environment : null,
            'payment_test_only' => $valid && $intent->environment !== 'production', 'payment_label' => $valid ? strtoupper($intent->environment) : null];
    }
}
