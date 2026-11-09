<?php

namespace App\Services;

use App\Models\User;
use App\Support\BookingRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(private PaymentGateway $gateway, private PaymentEligibility $eligibility) {}

    public function ownerBooking(User $owner, string $guid): object
    {
        $actor = DB::table('users')->where('guid', $owner->guid)->first(['role', 'status']);
        abort_unless($actor && (int) $actor->role === 0 && $actor->status === 'active', 404);
        $booking = DB::table('bookings')->join('destination_quotas as q', 'q.guid', '=', 'bookings.destination_quota_guid')
            ->join('destinations as d', 'd.guid', '=', 'q.destination_guid')
            ->where('bookings.guid', $guid)->where('bookings.user_guid', $owner->guid)
            ->first(['bookings.*', 'q.visit_date', 'd.timezone as destination_timezone']);
        abort_unless($booking, 404);

        return $booking;
    }

    public function methods(User $owner, string $guid): array
    {
        $booking = $this->ownerBooking($owner, $guid);

        $contract = $this->bookingContract($booking);
        $capabilities = $this->gateway->capabilities($this->instructionExpiresAt($booking, CarbonImmutable::now()));
        if (! $contract['can_pay'] && $capabilities['can_pay']) {
            $capabilities['methods'] = $this->unavailableMethods($capabilities['methods'], $contract['reason']);
        }

        return $contract + $capabilities;
    }

    public function adminShow(User $actor, string $guid): array
    {
        $scope = app(AdminDestinationScopeService::class);
        $actor = $scope->actor($actor);
        $booking = DB::table('bookings as b')->join('destination_quotas as q', 'q.guid', '=', 'b.destination_quota_guid')
            ->where('b.guid', $guid)->first(['b.guid', 'b.status', 'q.destination_guid']);
        abort_unless($booking, 404);
        $scope->authorize($actor, $booking->destination_guid);
        $intent = DB::table('payment_intents')->where('booking_guid', $guid)->first();
        $attempts = $intent ? DB::table('payment_attempts')->where('payment_intent_guid', $intent->guid)->orderByDesc('attempt_number')->limit(20)
            ->get(['guid', 'attempt_number', 'provider', 'environment', 'method', 'channel', 'status', 'amount', 'currency',
                'started_at', 'finished_at', 'verified_paid_at', 'reversed_at', 'last_reconciled_at', 'next_reconcile_at', 'reconcile_count', 'last_error_code', 'escalated_at', 'escalation_reason'])->all() : [];
        $events = $intent ? DB::table('payment_events')->where('payment_intent_guid', $intent->guid)->orderByDesc('received_at')->orderByDesc('guid')->limit(20)
            ->get(['guid', 'payment_attempt_guid', 'provider', 'environment', 'verification', 'event_type', 'disposition', 'amount', 'currency', 'provider_occurred_at', 'received_at'])->all() : [];

        return ['booking_guid' => $guid, 'booking_status' => $booking->status, 'intent_guid' => $intent?->guid,
            'status' => $intent?->status ?? 'not_created', 'provider' => $intent?->provider, 'environment' => $intent?->environment,
            'test_only' => $intent && $intent->environment !== 'production', 'attempts' => $attempts, 'events' => $events];
    }

    public function show(User $owner, string $guid): array
    {
        $booking = $this->ownerBooking($owner, $guid);
        $intent = DB::table('payment_intents')->where('booking_guid', $guid)
            ->first();
        $attempt = $intent ? DB::table('payment_attempts')->where('payment_intent_guid', $intent->guid)
            ->where('provider', $intent->provider)->where('environment', $intent->environment)
            ->orderByDesc('attempt_number')->first() : null;

        $capabilities = $this->gateway->capabilities($this->instructionExpiresAt($booking, CarbonImmutable::now()));
        if ($intent?->provider === 'demo') {
            $capabilities = array_replace($capabilities, ['provider' => 'demo', 'environment' => 'demo', 'test_only' => true, 'label' => 'DEMO']);
        }
        if ($intent?->provider === 'doku') {
            $capabilities = array_replace($capabilities, ['provider' => 'doku', 'environment' => $intent->environment, 'test_only' => $intent->environment !== 'production']);
        }
        $instruction = ['presentation_type' => $intent?->provider === 'demo' && $this->gateway->demoEnabled() ? 'demo_simulator' : 'unavailable'];
        if ($intent?->provider === 'doku' && $attempt) {
            $instruction = app(DokuPaymentService::class)->response($booking, $intent, $attempt)['instruction'];
        }
        $contract = $this->bookingContract($booking);
        if (! $contract['can_pay'] && $capabilities['can_pay']) {
            $capabilities['methods'] = $this->unavailableMethods($capabilities['methods'], $contract['reason']);
        }

        return $contract + $capabilities + [
            'intent_guid' => $intent?->guid,
            'status' => $intent?->status ?? 'not_created',
            'last_attempt' => $attempt ? [
                'attempt_guid' => $attempt->guid, 'status' => $attempt->status,
                'method' => $attempt->method, 'channel' => $attempt->channel,
                'started_at' => $this->timestamp($attempt->started_at),
                'finished_at' => $this->timestamp($attempt->finished_at),
                'expires_at' => $this->timestamp($attempt->instruction_expires_at),
                // Past its window without a proven terminal outcome: the customer must not retry or pay again, and the booking
                // keeps its quota until the provider status is verified.
                'verification_pending' => $this->verificationPending($attempt),
                'escalated' => $this->verificationPending($attempt) && $attempt->escalated_at !== null,
            ] : null,
            'instruction' => $instruction,
        ];
    }

    private function hasOverdueOpenAttempt(string $bookingGuid): bool
    {
        return DB::table('payment_attempts as a')->join('payment_intents as i', 'i.guid', '=', 'a.payment_intent_guid')->where('i.booking_guid', $bookingGuid)
            ->whereIn('a.status', ['initiating', 'pending', 'unknown'])->where('a.instruction_expires_at', '<=', now())->exists();
    }

    public function verificationPending(object $attempt): bool
    {
        return in_array($attempt->status, ['initiating', 'pending', 'unknown'], true)
            && $attempt->instruction_expires_at !== null && now()->gte($attempt->instruction_expires_at);
    }

    private function bookingContract(object $booking): array
    {
        $reason = match (true) {
            $booking->status !== 'awaiting_payment' => 'booking_not_awaiting_payment',
            (int) $booking->total_amount <= 0 => 'booking_not_payable',
            $booking->currency !== 'IDR' => 'unsupported_currency',
            ! $booking->payment_expires_at || now()->gte($booking->payment_expires_at) => 'payment_window_closed',
            now()->gte(BookingRules::visitStart($booking->visit_date, $booking->destination_timezone_snapshot ?? $booking->destination_timezone)) => 'visit_window_closed',
            $this->gateway->demoEnabled() && DB::table('payment_intents')->where('booking_guid', $booking->guid)
                ->where(fn ($intent) => $intent->where('provider', '!=', 'demo')->orWhere('environment', '!=', 'demo'))->exists() => 'payment_provider_conflict',
            ! $this->gateway->demoEnabled() && DB::table('payment_intents')->where('booking_guid', $booking->guid)
                ->where(fn ($intent) => $intent->where('provider', '!=', 'doku')->orWhere('environment', '!=', config('doku.environment', 'sandbox')))->exists() => 'payment_provider_conflict',
            DB::table('payment_intents')->where('booking_guid', $booking->guid)->where('status', 'needs_review')->exists() => 'payment_needs_review',
            $this->hasOverdueOpenAttempt($booking->guid) => 'payment_verification_pending',
            default => $this->gateway->capabilities($this->instructionExpiresAt($booking, CarbonImmutable::now()))['reason'],
        };

        return $this->invoiceContract($booking) + [
            'booking_guid' => $booking->guid, 'booking_status' => $booking->status,
            'availability' => $reason === null ? ($this->gateway->demoEnabled() ? 'demo' : 'available') : 'unavailable', 'reason' => $reason,
            'can_pay' => $reason === null,
            'amount' => (int) $booking->total_amount, 'currency' => $booking->currency,
            'payment_status' => $this->eligibility->status($booking),
            'expires_at' => $this->timestamp($booking->payment_expires_at),
        ];
    }

    /** @return array<string, mixed> */
    public function invoiceContract(object $booking, ?object $intent = null): array
    {
        $intent ??= DB::table('payment_intents')->where('booking_guid', $booking->guid)->first();
        $unresolved = $intent && DB::table('payment_attempts')->where('payment_intent_guid', $intent->guid)
            ->whereIn('status', ['initiating', 'pending', 'unknown'])->exists();
        $eligible = $booking->status === 'awaiting_payment' && (int) $booking->total_amount > 0 && $booking->currency === 'IDR'
            && $booking->payment_expires_at && now()->lt($booking->payment_expires_at)
            && now()->lt(BookingRules::visitStart($booking->visit_date, $booking->destination_timezone_snapshot ?? $booking->destination_timezone))
            && DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'awaiting_payment')->exists();
        $provider = $this->gateway->demoEnabled() ? 'demo' : 'doku';
        $environment = $provider === 'demo' ? 'demo' : config('doku.environment', 'sandbox');

        return [
            'invoice_number' => DB::table('invoices')->where('booking_guid', $booking->guid)->value('invoice_number'),
            'booking_number' => $booking->booking_number, 'quantity' => (int) $booking->quantity,
            'unit_price' => (int) $booking->unit_price, 'subtotal' => (int) $booking->total_amount,
            'service_fee' => 0, 'total_amount' => (int) $booking->total_amount,
            'paid_at' => $this->timestamp($intent?->verified_paid_at ?? null),
            'refund_request' => app(BookingRefundService::class)->latestSummary($booking->guid),
            'applied_attempt_guid' => $intent?->applied_attempt_guid ?? null, 'receipt_available' => app(PaymentReceiptService::class)->data($booking->guid) !== null,
            'can_create' => $eligible && ! $unresolved && $this->gateway->capabilities($this->instructionExpiresAt($booking, CarbonImmutable::now()))['can_pay']
                && (! $intent || ($intent->provider === $provider && $intent->environment === $environment
                    && in_array($intent->status, ['requires_payment', 'processing'], true))),
            'can_switch' => false,
            'switch_reason' => $unresolved ? 'payment_attempt_unresolved' : 'payment_switch_contract_unverified',
        ];
    }

    public function instructionExpiresAt(object $booking, CarbonImmutable $startedAt): CarbonImmutable
    {
        return $startedAt->addMinutes(30)
            ->min($booking->payment_expires_at ?? $startedAt)
            ->min(BookingRules::visitStart($booking->visit_date, $booking->destination_timezone_snapshot ?? $booking->destination_timezone));
    }

    /** @param list<array<string, mixed>> $methods
     * @return list<array<string, mixed>>
     */
    private function unavailableMethods(array $methods, ?string $reason): array
    {
        return array_map(function (array $method) use ($reason): array {
            $method['available'] = false;
            $method['reason'] = $reason;
            if (isset($method['channels'])) {
                $method['channels'] = array_map(fn (array $channel): array => array_replace($channel, ['available' => false, 'reason' => $reason]), $method['channels']);
            }

            return $method;
        }, $methods);
    }

    private function timestamp(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->toIso8601String();
    }
}
