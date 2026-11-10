<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Jobs\IssueBookingTicketQr;
use App\Support\BookingRules;
use App\Support\PaymentStates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PaymentSettlementService
{
    public function __construct(private TicketService $tickets, private BookingNotificationService $notifications) {}

    private function lockedBooking(string $guid, string $quota): object
    {
        abort_unless(DB::table('destination_quotas')->where('guid', $quota)->lockForUpdate()->first(['guid']), 404);
        $booking = $this->tickets->booking($guid, true);
        if ($booking->destination_quota_guid !== $quota) {
            throw new BookingException('Booking quota changed.', 'payment_needs_review');
        }

        return $booking;
    }

    public function apply(string $eventGuid, ?string $rejectionReason = null): void
    {
        $reference = DB::table('payment_events as e')->join('payment_intents as i', 'i.guid', '=', 'e.payment_intent_guid')
            ->join('bookings as b', 'b.guid', '=', 'i.booking_guid')->where('e.guid', $eventGuid)->first(['b.guid', 'b.destination_quota_guid']);
        if (! $reference) {
            return;
        }
        DB::transaction(function () use ($eventGuid, $reference, $rejectionReason): void {
            $booking = $this->lockedBooking($reference->guid, $reference->destination_quota_guid);
            $intent = DB::table('payment_intents')->where('booking_guid', $booking->guid)->lockForUpdate()->first();
            $eventRef = DB::table('payment_events')->where('guid', $eventGuid)->first();
            $attempt = DB::table('payment_attempts')->where('guid', $eventRef->payment_attempt_guid)->lockForUpdate()->first();
            $event = DB::table('payment_events')->where('guid', $eventGuid)->lockForUpdate()->first();
            if (! $event || $event->disposition !== 'recorded' || $event->verification !== 'verified') {
                return;
            }
            if (! $attempt || ! in_array($intent->provider, ['doku', 'midtrans'], true)
                || ! in_array($intent->environment, ['sandbox', 'production'], true)
                || $event->environment !== $intent->environment || $attempt->environment !== $intent->environment
                || $attempt->provider !== $intent->provider || $event->provider !== $intent->provider) {
                DB::table('payment_events')->where('guid', $event->guid)->update(['disposition' => 'quarantined']);

                return;
            }
            if ($rejectionReason !== null) {
                $this->review($booking, $intent, $event, $rejectionReason);

                return;
            }
            $outcome = json_decode($event->metadata ?? '{}', true, 512, JSON_THROW_ON_ERROR)['outcome'] ?? [];
            $outcome = is_array($outcome) ? $outcome : [];
            try {
                $timeMatches = isset($outcome['occurred_at']) && CarbonImmutable::parse($outcome['occurred_at'])->utc()
                    ->equalTo(CarbonImmutable::parse($event->provider_occurred_at, 'UTC'));
            } catch (Throwable) {
                $timeMatches = false;
            }
            if ($event->provider_occurred_at && CarbonImmutable::parse($event->provider_occurred_at)->gt(now())
                && CarbonImmutable::parse($event->provider_occurred_at)->lte(now()->addMinutes(5))) {
                return;
            }
            $expectedType = match ($outcome['status'] ?? null) {
                'paid' => 'capture', 'reversed' => 'reversal', 'refunded' => 'refund', default => 'status',
            };
            $matches = $timeMatches
                && (! array_key_exists('provider', $outcome) || $outcome['provider'] === $intent->provider)
                && (! array_key_exists('environment', $outcome) || $outcome['environment'] === $intent->environment)
                && $attempt->payment_intent_guid === $intent->guid && $event->payment_intent_guid === $intent->guid
                && $event->event_type === $expectedType && ! empty($outcome['provider_reference'])
                && $attempt->merchant_reference === ($outcome['merchant_reference'] ?? null)
                && ($attempt->provider_reference === null || $attempt->provider_reference === $outcome['provider_reference'])
                && (! array_key_exists('method', $outcome) || $outcome['method'] === $attempt->method)
                && (! array_key_exists('channel', $outcome) || $outcome['channel'] === $attempt->channel)
                && (int) $attempt->amount > 0 && (int) $attempt->amount === (int) $intent->amount && (int) $intent->amount === (int) $booking->total_amount
                && (int) ($event->amount ?? 0) === (int) $attempt->amount && (int) ($outcome['amount'] ?? 0) === (int) $event->amount
                && $attempt->currency === 'IDR' && ($outcome['currency'] ?? null) === $event->currency
                && $event->currency === $attempt->currency && $intent->currency === $attempt->currency && $booking->currency === $intent->currency
                && $intent->expires_at === $booking->payment_expires_at;
            if (! $matches || ! $event->provider_occurred_at || CarbonImmutable::parse($event->provider_occurred_at)->gt(now())
                || CarbonImmutable::parse($event->provider_occurred_at)->lt($attempt->started_at)) {
                $this->review($booking, $intent, $event, 'snapshot_mismatch');

                return;
            }
            if ($outcome['status'] === 'paid') {
                $this->capture($booking, $intent, $attempt, $event, $outcome);
            } elseif (in_array($outcome['status'], ['reversed', 'refunded'], true)) {
                $capture = DB::table('payment_attempts as a')->join('payment_intents as i', 'i.guid', '=', 'a.payment_intent_guid')
                    ->where('a.guid', $attempt->guid)->whereExists(app(PaymentEligibility::class)->boundEvents('capture')
                    ->whereIn('e.disposition', ['applied', 'unapplied'])->whereColumn('e.provider_occurred_at', 'a.verified_paid_at'))->exists();
                if ($attempt->verified_paid_at === null || ! $capture || $event->provider_occurred_at < $attempt->verified_paid_at) {
                    $this->review($booking, $intent, $event, 'adjustment_without_capture');

                    return;
                }
                if ($attempt->reversed_at !== null) {
                    DB::table('payment_events')->where('guid', $event->guid)->update(['disposition' => 'duplicate_adjustment']);

                    return;
                }
                DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['status' => 'reversed', 'reversed_at' => $event->provider_occurred_at, 'next_reconcile_at' => null, 'updated_at' => now()]);
                $this->review($booking, $intent, $event, 'provider_adjustment', 'applied');
                if ($attempt->guid === $intent->applied_attempt_guid) {
                    app(BookingRefundService::class)->recordVerifiedAdjustment($booking->guid, $attempt->guid, $event->guid, $event->event_type);
                }
            } elseif (! in_array($attempt->status, ['paid', 'reversed'], true) && in_array($outcome['status'], ['pending', 'failed', 'expired', 'unknown'], true)) {
                if ($attempt->status !== $outcome['status'] && ! in_array($outcome['status'], PaymentStates::ATTEMPT_TRANSITIONS[$attempt->status], true)) {
                    DB::table('payment_events')->where('guid', $event->guid)->update(['disposition' => 'ignored']);

                    return;
                }
                $terminal = in_array($outcome['status'], ['failed', 'expired'], true);
                DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['status' => $outcome['status'],
                    'finished_at' => $terminal ? now() : null, 'next_reconcile_at' => $terminal ? null : now()->addMinutes(5), 'updated_at' => now()]);
                if ($terminal && $intent->status === 'processing' && ! DB::table('payment_attempts')->where('payment_intent_guid', $intent->guid)
                    ->where('attempt_number', '>', $attempt->attempt_number)->exists()) {
                    DB::table('payment_intents')->where('guid', $intent->guid)->update(['status' => 'requires_payment', 'updated_at' => now()]);
                }
                DB::table('payment_events')->where('guid', $event->guid)->update(['disposition' => 'applied']);
            } else {
                DB::table('payment_events')->where('guid', $event->guid)->update(['disposition' => 'ignored']);
            }
        }, 3);
    }

    private function capture(object $booking, object $intent, object $attempt, object $event, array $outcome): void
    {
        if ($attempt->verified_paid_at !== null || $attempt->reversed_at !== null) {
            DB::table('payment_events')->where('guid', $event->guid)->update(['disposition' => 'duplicate_capture']);

            return;
        }
        DB::table('payment_attempts')->where('guid', $attempt->guid)->update([
            'status' => 'paid', 'verified_paid_at' => $event->provider_occurred_at, 'finished_at' => now(), 'next_reconcile_at' => null,
            'provider_reference' => $attempt->provider_reference ?? $outcome['provider_reference'], 'updated_at' => now(),
        ]);
        $eligible = in_array($booking->status, ['awaiting_payment', 'payment_verification', 'payment_review'], true)
            && (! app()->environment('production') || $intent->environment === 'production')
            && in_array($intent->status, ['processing', 'requires_payment'], true) && $intent->applied_attempt_guid === null
            && in_array($attempt->status, ['initiating', 'pending', 'unknown'], true)
            && ! DB::table('payment_attempts')->where('payment_intent_guid', $intent->guid)->where('attempt_number', '>', $attempt->attempt_number)->exists()
            && CarbonImmutable::parse($event->provider_occurred_at)->lt($booking->payment_expires_at)
            && CarbonImmutable::parse($event->provider_occurred_at)->lt($attempt->instruction_expires_at)
            && now()->lt(BookingRules::visitStart($booking->visit_date, $booking->destination_timezone))
            && DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'awaiting_payment')->exists();
        if (! $eligible) {
            $this->review($booking, $intent, $event, 'payment_not_applicable', 'unapplied');

            return;
        }
        DB::table('payment_intents')->where('guid', $intent->guid)->update(['status' => 'paid', 'applied_attempt_guid' => $attempt->guid,
            'verified_paid_at' => $event->provider_occurred_at, 'updated_at' => now()]);
        DB::table('bookings')->where('guid', $booking->guid)->update(['status' => 'confirmed', 'updated_at' => now()]);
        DB::table('booking_status_histories')->insert(['guid' => (string) Str::uuid(), 'booking_guid' => $booking->guid, 'actor_guid' => null,
            'old_status' => $booking->status, 'new_status' => 'confirmed', 'reason' => 'Verified '.strtoupper($intent->provider).' '.$intent->environment.' payment.', 'created_at' => now()]);
        DB::table('payment_events')->where('guid', $event->guid)->update(['disposition' => 'applied']);
        $this->notifications->recordPaymentReceipt($booking->guid, $booking->user_guid, $attempt->guid);
        if (app(TicketService::class)->qrWindowOpen($booking)) {
            $bookingGuid = $booking->guid;
            // Queue publication runs after commit and inside its own guard: a broken queue must never turn an applied payment
            // into an error. tickets:issue-qr is the recovery path.
            DB::afterCommit(function () use ($bookingGuid): void {
                try {
                    IssueBookingTicketQr::dispatch($bookingGuid);
                } catch (Throwable $exception) {
                    Log::warning('Ticket QR job could not be queued.', ['booking_guid' => $bookingGuid, 'exception_type' => $exception::class]);
                }
            });
        }
    }

    private function review(object $booking, object $intent, object $event, string $reason, string $disposition = 'quarantined'): void
    {
        DB::table('payment_intents')->where('guid', $intent->guid)->update(['status' => 'needs_review', 'updated_at' => now()]);
        if ($disposition !== 'applied' && in_array($event->event_type, ['refund', 'reversal'], true)) {
            app(BookingRefundService::class)->flagForReview($booking->guid, $reason);
        }
        $metadata = json_decode($event->metadata, true, 512, JSON_THROW_ON_ERROR);
        DB::table('payment_events')->where('guid', $event->guid)->update(['disposition' => $disposition, 'metadata' => json_encode($metadata + ['reason' => $reason], JSON_THROW_ON_ERROR)]);
        DB::table('booking_tickets')->where('booking_guid', $booking->guid)->whereNull('checked_in_at')->whereNotNull('qr_token_hash')
            ->whereNull('qr_revoked_at')->update(['qr_revoked_at' => now(), 'updated_at' => now()]);
        $this->notifications->record($booking->guid, $booking->user_guid, 'payment_review');
        Log::warning('Payment needs review.', ['intent_guid' => $intent->guid, 'event_guid' => $event->guid, 'reason' => $reason]);
    }
}
