<?php

namespace App\Services;

use App\Services\Doku\DokuChannelRegistry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PaymentEligibility
{
    public function status(object $booking): string
    {
        if (isset($booking->has_payment) && ! $booking->has_payment) {
            return 'unverified';
        }
        $paid = DB::table('payment_intents as i')
            ->join('payment_attempts as a', 'a.guid', '=', 'i.applied_attempt_guid')
            ->join('bookings as b', 'b.guid', '=', 'i.booking_guid')
            ->where('b.guid', $booking->guid)->where('b.status', 'confirmed')->where('b.total_amount', '>', 0)
            ->where(function (Builder $provider): void {
                $provider->where(fn (Builder $doku) => $doku->where('i.provider', 'doku')->where('i.environment', config('doku.environment', 'sandbox'))
                    ->whereIn('i.environment', ['sandbox', 'production'])->whereNotNull('a.provider_reference')
                    ->where(function (Builder $channel): void {
                        foreach (app(DokuChannelRegistry::class)->settlingPairs() as [$method, $name]) {
                            $channel->orWhere(fn (Builder $pair) => $pair->where('a.method', $method)->where('a.channel', $name));
                        }
                    }));
                if (app(PaymentGateway::class)->demoEnabled()) {
                    $provider->orWhere(fn (Builder $demo) => $demo->where('i.provider', 'demo')->where('i.environment', 'demo'));
                }
            })->where('i.status', 'paid')->where('a.status', 'paid')
            ->whereColumn('a.provider', 'i.provider')->whereColumn('a.environment', 'i.environment')
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
                    ->whereColumn('e.payment_attempt_guid', 'a.guid')->whereColumn('e.provider', 'i.provider')->whereColumn('e.environment', 'i.environment')
                    ->where('e.verification', 'verified')->where('e.event_type', 'capture')->where('e.disposition', 'applied')
                    ->whereColumn('e.amount', 'a.amount')->whereColumn('e.currency', 'a.currency')
                    ->whereColumn('e.provider_occurred_at', 'a.verified_paid_at')
                    ->where(fn (Builder $source) => $source->where(fn (Builder $demo) => $demo->where('i.provider', 'demo')->where('e.metadata->source', 'demo_simulator'))
                        ->orWhere(fn (Builder $doku) => $doku->where('i.provider', 'doku')->where('e.metadata->source', 'doku_snap')));
            })
            ->whereNotExists(function (Builder $event): void {
                $event->selectRaw('1')->from('payment_events as e')->whereColumn('e.payment_intent_guid', 'i.guid')
                    ->whereColumn('e.payment_attempt_guid', 'a.guid')->whereColumn('e.provider', 'i.provider')->whereColumn('e.environment', 'i.environment')
                    ->where('e.verification', 'verified')->whereIn('e.event_type', ['refund', 'reversal'])
                    ->where('e.disposition', 'applied');
            })->exists();

        if ($paid) {
            return 'paid';
        }
        $reversed = DB::table('payment_intents as i')->join('payment_attempts as a', 'a.guid', '=', 'i.applied_attempt_guid')
            ->join('payment_events as e', 'e.payment_attempt_guid', '=', 'a.guid')->where('i.booking_guid', $booking->guid)
            ->where('i.provider', 'doku')->where('i.environment', config('doku.environment', 'sandbox'))
            ->whereColumn('a.provider', 'i.provider')->whereColumn('a.environment', 'i.environment')->where('a.status', 'reversed')
            ->whereColumn('a.payment_intent_guid', 'i.guid')->whereColumn('e.payment_intent_guid', 'i.guid')
            ->whereColumn('e.provider', 'i.provider')->whereColumn('e.environment', 'i.environment')
            ->where('e.verification', 'verified')->where('e.disposition', 'applied')->whereIn('e.event_type', ['refund', 'reversal'])
            ->where('e.metadata->source', 'doku_snap')->whereNotNull('a.reversed_at')->exists();

        return $reversed ? 'reversed' : 'unverified';
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
            || ($intent->provider === 'doku' && in_array($intent->environment, ['sandbox', 'production'], true)));

        return ['payment_provider' => $valid ? $intent->provider : null, 'payment_environment' => $valid ? $intent->environment : null,
            'payment_test_only' => $valid && $intent->environment !== 'production', 'payment_label' => $valid ? strtoupper($intent->environment) : null];
    }
}
