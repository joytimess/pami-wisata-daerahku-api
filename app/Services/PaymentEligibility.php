<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PaymentEligibility
{
    public function status(object $booking): string
    {
        if (! app(PaymentGateway::class)->demoEnabled() || (isset($booking->has_demo_payment) && ! $booking->has_demo_payment)) {
            return 'unverified';
        }

        $paid = DB::table('payment_intents as i')
            ->join('payment_attempts as a', 'a.guid', '=', 'i.applied_attempt_guid')
            ->join('bookings as b', 'b.guid', '=', 'i.booking_guid')
            ->where('b.guid', $booking->guid)->where('b.status', 'confirmed')->where('b.total_amount', '>', 0)
            ->where('i.provider', 'demo')->where('i.environment', 'demo')->where('i.status', 'paid')
            ->where('a.provider', 'demo')->where('a.environment', 'demo')->where('a.status', 'paid')
            ->whereColumn('a.payment_intent_guid', 'i.guid')->whereColumn('a.amount', 'i.amount')
            ->whereColumn('i.amount', 'b.total_amount')->where('i.currency', 'IDR')
            ->whereColumn('a.currency', 'i.currency')->whereColumn('b.currency', 'i.currency')
            ->whereColumn('i.verified_paid_at', 'a.verified_paid_at')->whereColumn('i.expires_at', 'b.payment_expires_at')
            ->whereColumn('a.verified_paid_at', '>=', 'a.started_at')
            ->whereColumn('a.verified_paid_at', '<', 'b.payment_expires_at')->whereNull('a.reversed_at')
            ->whereExists(fn (Builder $history) => $history->selectRaw('1')->from('booking_status_histories as h')
                ->whereColumn('h.booking_guid', 'b.guid')->where('h.new_status', 'awaiting_payment'))
            ->whereExists(function (Builder $event): void {
                $event->selectRaw('1')->from('payment_events as e')->whereColumn('e.payment_intent_guid', 'i.guid')
                    ->whereColumn('e.payment_attempt_guid', 'a.guid')->where('e.provider', 'demo')->where('e.environment', 'demo')
                    ->where('e.verification', 'verified')->where('e.event_type', 'capture')->where('e.disposition', 'applied')
                    ->whereColumn('e.amount', 'a.amount')->whereColumn('e.currency', 'a.currency')
                    ->whereColumn('e.provider_occurred_at', 'a.verified_paid_at')->where('e.metadata->source', 'demo_simulator');
            })
            ->whereNotExists(function (Builder $event): void {
                $event->selectRaw('1')->from('payment_events as e')->whereColumn('e.payment_intent_guid', 'i.guid')
                    ->whereColumn('e.payment_attempt_guid', 'a.guid')->where('e.provider', 'demo')->where('e.environment', 'demo')
                    ->where('e.verification', 'verified')->whereIn('e.event_type', ['refund', 'reversal'])
                    ->where('e.disposition', 'applied');
            })->exists();

        return $paid ? 'paid' : 'unverified';
    }

    /** @return array{payment_provider: ?string, payment_environment: ?string, payment_test_only: bool, payment_label: ?string} */
    public function provenance(object $booking): array
    {
        $demo = isset($booking->has_demo_payment) ? (bool) $booking->has_demo_payment
            : DB::table('payment_intents')->where('booking_guid', $booking->guid)
                ->where('provider', 'demo')->where('environment', 'demo')->exists();

        return ['payment_provider' => $demo ? 'demo' : null, 'payment_environment' => $demo ? 'demo' : null,
            'payment_test_only' => $demo, 'payment_label' => $demo ? 'DEMO' : null];
    }
}
