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

        return $this->bookingContract($booking) + $this->gateway->capabilities();
    }

    public function show(User $owner, string $guid): array
    {
        $booking = $this->ownerBooking($owner, $guid);
        $intent = DB::table('payment_intents')->where('booking_guid', $guid)
            ->where(function ($query): void {
                $query->where(fn ($demo) => $demo->where('provider', 'demo')->where('environment', 'demo'))
                    ->orWhere(fn ($doku) => $doku->where('provider', 'doku')->where('environment', 'sandbox'));
            })->first();
        $attempt = $intent ? DB::table('payment_attempts')->where('payment_intent_guid', $intent->guid)
            ->where('provider', $intent->provider)->where('environment', $intent->environment)
            ->orderByDesc('attempt_number')->first(['guid', 'status', 'method', 'channel', 'started_at', 'finished_at', 'instruction_expires_at']) : null;

        $capabilities = $this->gateway->capabilities();
        if ($intent?->provider === 'demo') {
            $capabilities = array_replace($capabilities, ['provider' => 'demo', 'environment' => 'demo', 'test_only' => true, 'label' => 'DEMO']);
        }

        return $this->bookingContract($booking) + $capabilities + [
            'intent_guid' => $intent?->guid,
            'status' => $intent?->status ?? 'not_created',
            'last_attempt' => $attempt ? [
                'attempt_guid' => $attempt->guid, 'status' => $attempt->status,
                'method' => $attempt->method, 'channel' => $attempt->channel,
                'started_at' => $this->timestamp($attempt->started_at),
                'finished_at' => $this->timestamp($attempt->finished_at),
                'expires_at' => $this->timestamp($attempt->instruction_expires_at),
            ] : null,
            'instruction' => ['presentation_type' => $intent?->provider === 'demo' && $this->gateway->demoEnabled() ? 'demo_simulator' : 'unavailable'],
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
            default => $this->gateway->demoEnabled() ? null : 'doku_not_configured',
        };

        return [
            'booking_guid' => $booking->guid, 'booking_status' => $booking->status,
            'availability' => $reason === null ? 'demo' : 'unavailable', 'reason' => $reason,
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
