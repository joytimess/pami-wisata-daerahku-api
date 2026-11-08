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
        $capabilities = $this->gateway->capabilities();
        if (! $contract['can_pay']) {
            $capabilities['methods'] = array_map(fn (array $method): array => array_replace($method, ['available' => false, 'reason' => $contract['reason']]), $capabilities['methods']);
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
                'started_at', 'finished_at', 'verified_paid_at', 'reversed_at', 'last_reconciled_at', 'next_reconcile_at', 'reconcile_count', 'last_error_code'])->all() : [];
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

        $capabilities = $this->gateway->capabilities();
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
        if (! $contract['can_pay']) {
            $capabilities['methods'] = array_map(fn (array $method): array => array_replace($method, ['available' => false, 'reason' => $contract['reason']]), $capabilities['methods']);
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
            ] : null,
            'instruction' => $instruction,
        ];
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
            default => $this->gateway->capabilities()['reason'],
        };

        return [
            'booking_guid' => $booking->guid, 'booking_status' => $booking->status,
            'availability' => $reason === null ? ($this->gateway->demoEnabled() ? 'demo' : 'available') : 'unavailable', 'reason' => $reason,
            'can_pay' => $reason === null,
            'amount' => (int) $booking->total_amount, 'currency' => $booking->currency,
            'payment_status' => $this->eligibility->status($booking),
            'expires_at' => $this->timestamp($booking->payment_expires_at),
        ];
    }

    private function timestamp(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->toIso8601String();
    }
}
