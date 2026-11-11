<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Jobs\ProcessMidtransRefund;
use App\Models\User;
use App\Services\Midtrans\MidtransClient;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Full-refund workflow (request -> scoped admin decision -> manual transfer OR provider API -> verified outcome). Full amount only.
 *
 * Approval never moves money and never writes `refunded`: that status is set only by recordVerifiedAdjustment(), which the
 * payment engine calls after it has applied a verified provider refund/reversal event, or `manual_refunded` after an admin
 * verified an out-of-band transfer.
 *
 * Two routes follow an approval. DOKU, BCA/BNI virtual accounts and any Midtrans payment whose refund capability is not proven are
 * `manual_action_required`. A Midtrans QRIS payment with the API capability enabled is CLAIMED first: the refund key and an admission
 * hold are committed (status `refund_pending`) before any provider call; the worker then sends the SAME key however often it must,
 * and the refund is final only after the Status API shows the full refund.
 *
 * Lock order matches payments and check-in: destination -> quota -> booking -> intent -> attempt -> refund request -> tickets.
 */
class BookingRefundService
{
    /** Statuses that keep the single per-booking slot occupied. */
    public const ACTIVE_STATUSES = ['requested', 'approved', 'manual_action_required', 'refund_pending', 'needs_review'];

    /** Terminal statuses meaning the customer's money was returned, by either channel. */
    public const SETTLED_STATUSES = ['refunded', 'manual_refunded'];

    /** Accepted manual settlement methods; the reference must identify the transfer/disbursement in that channel. */
    public const SETTLEMENT_METHODS = ['bank_transfer', 'ewallet_transfer', 'cash_disbursement'];

    /** A worker's claim on one refund step; a crashed worker's lease simply expires. */
    private const LEASE_SECONDS = 120;

    /** Unverified passes before a provider refund is escalated to a human (the refund key and the hold are kept). */
    private const RECONCILE_MAX = 20;

    /** Sends of one refund key before a still-ambiguous dispatch is escalated. */
    private const DISPATCH_MAX = 5;

    public function __construct(private PaymentEligibility $payments, private PaymentService $paymentService) {}

    /**
     * @param  array{idempotency_key: string, reason: string}  $input
     * @return array<string, mixed>
     */
    public function request(User $owner, string $guid, array $input): array
    {
        $this->paymentService->ownerBooking($owner, $guid);
        $reason = trim($input['reason']);

        return DB::transaction(function () use ($owner, $guid, $input, $reason): array {
            $booking = $this->lockBooking($guid);
            abort_unless($booking->user_guid === $owner->guid, 404);
            $existing = DB::table('booking_refund_requests')->where('booking_guid', $guid)->where('idempotency_key', $input['idempotency_key'])->lockForUpdate()->first();
            if ($existing) {
                if ($existing->reason !== $reason) {
                    throw new BookingException('Idempotency key already used with different input.', 'idempotency_conflict');
                }

                return $this->summary($existing);
            }
            if (DB::table('booking_refund_requests')->where('booking_guid', $guid)->whereIn('status', self::SETTLED_STATUSES)->exists()) {
                throw new BookingException('This booking has already been refunded.', 'refund_already_settled');
            }
            $active = DB::table('booking_refund_requests')->where('booking_guid', $guid)->where('active', 1)->lockForUpdate()->first();
            if ($active) {
                throw new BookingException('A refund request is already open for this booking.', 'refund_already_requested', ['refund_guid' => $active->guid]);
            }
            $intent = DB::table('payment_intents')->where('booking_guid', $guid)->whereIn('provider', ['doku', 'midtrans'])->lockForUpdate()->first();
            if (! $intent || $intent->applied_attempt_guid === null || $booking->status !== 'confirmed' || $this->payments->entitlement($booking) !== 'paid') {
                throw new BookingException('This booking has no verified payment that can be refunded.', 'refund_not_available');
            }
            if ($this->usedAdmission($guid)) {
                throw new BookingException('A ticket for this booking has already been used.', 'ticket_already_used');
            }
            $now = now();
            $refundGuid = (string) Str::uuid();
            DB::table('booking_refund_requests')->insert([
                'guid' => $refundGuid, 'booking_guid' => $guid, 'payment_intent_guid' => $intent->guid, 'payment_attempt_guid' => $intent->applied_attempt_guid,
                'requested_by_user_guid' => $owner->guid, 'idempotency_key' => $input['idempotency_key'], 'status' => 'requested', 'active' => 1,
                'amount' => (int) $intent->amount, 'currency' => $intent->currency, 'reason' => $reason, 'requested_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->audit($refundGuid, $owner->guid, null, 'requested', null);

            return $this->summary(DB::table('booking_refund_requests')->where('guid', $refundGuid)->first());
        }, 3);
    }

    /**
     * @param  array{decision: string, note?: ?string}  $input
     * @return array<string, mixed>
     */
    public function decide(User $actor, string $bookingGuid, string $refundGuid, array $input): array
    {
        if ((int) $actor->role < 1) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $bookingGuid, $refundGuid, $input): array {
            $ref = DB::table('bookings')->where('guid', $bookingGuid)->first(['destination_quota_guid']) ?? throw new ModelNotFoundException;
            $destination = DB::table('destination_quotas')->where('guid', $ref->destination_quota_guid)->value('destination_guid');
            app(AdminDestinationScopeService::class)->lockDestination($destination);
            $booking = $this->lockBooking($bookingGuid);
            $actor = app(AdminDestinationScopeService::class)->authorize($actor, $destination, true);
            // Intent and attempt before the request row, exactly as settlement takes them, so the two can never wait on each other.
            $peek = DB::table('booking_refund_requests')->where('guid', $refundGuid)->where('booking_guid', $bookingGuid)->first() ?? throw new ModelNotFoundException;
            $intent = DB::table('payment_intents')->where('guid', $peek->payment_intent_guid)->lockForUpdate()->first();
            $attempt = DB::table('payment_attempts')->where('guid', $peek->payment_attempt_guid)->lockForUpdate()->first();
            $request = DB::table('booking_refund_requests')->where('guid', $refundGuid)->where('booking_guid', $bookingGuid)->lockForUpdate()->first()
                ?? throw new ModelNotFoundException;
            if ($request->status !== 'requested') {
                throw new BookingException('This refund request was already decided.', 'refund_not_pending', ['status' => $request->status]);
            }
            $note = isset($input['note']) ? trim((string) $input['note']) : null;
            if ($input['decision'] === 'reject') {
                $this->move($request, 'rejected', $actor->guid, $note, ['active' => null, 'decided_by_user_guid' => $actor->guid, 'decided_at' => now(), 'decision_note' => $note]);
            } elseif ($this->usedAdmission($bookingGuid) || $this->payments->entitlement($booking) !== 'paid' || $booking->status !== 'confirmed'
                || ! $this->paymentStillSettled($intent, $attempt)
                || DB::table('booking_refund_requests')->where('booking_guid', $bookingGuid)->whereIn('status', self::SETTLED_STATUSES)->exists()) {
                $this->move($request, 'needs_review', $actor->guid, $note, ['review_reason' => 'usage_or_payment_changed', 'decided_by_user_guid' => $actor->guid, 'decided_at' => now()]);
            } else {
                $this->move($request, 'approved', $actor->guid, $note, ['decided_by_user_guid' => $actor->guid, 'decided_at' => now(), 'decision_note' => $note]);
                $approved = DB::table('booking_refund_requests')->where('guid', $refundGuid)->first();
                $unavailable = $this->providerRefundUnavailable($intent, $attempt);
                if ($unavailable === null) {
                    // The claim: key, route and admission hold are durable BEFORE any provider call, in the same transaction that
                    // proved no ticket was used. A check-in can only win by committing first, and then this branch is never reached.
                    $this->move($approved, 'refund_pending', null, 'provider_refund_claimed', [
                        'refund_route' => 'provider_api', 'refund_key' => self::refundKey($refundGuid), 'admission_hold' => 1,
                        'next_reconcile_at' => now()->addSeconds(30), 'reconcile_count' => 0,
                    ]);
                    DB::afterCommit(fn () => $this->queueProviderRefund($refundGuid));
                } else {
                    // Money movement is a human action; the reason stays in the audit trail.
                    $this->move($approved, 'manual_action_required', null, $unavailable === 'provider_not_supported' ? 'provider_refund_unavailable' : 'provider_refund_unavailable:'.$unavailable,
                        $intent?->provider === 'midtrans' ? ['refund_route' => 'manual'] : []);
                }
            }

            return $this->summary(DB::table('booking_refund_requests')->where('guid', $refundGuid)->first());
        }, 3);
    }

    /**
     * Records that an admin verified a manual transfer/disbursement returning the money. Only a request already approved into
     * `manual_action_required` can be settled, for exactly the requested amount, once per transfer reference. It writes no payment_*
     * row and fabricates no DOKU event: the original payment history is untouched. Unused QR units are revoked in the same
     * transaction; a booking whose admission was used is sent to review instead and nothing is settled.
     *
     * @param  array{settlement_method: string, settlement_reference: string, settlement_amount: int, settled_at: string, evidence_note: string}  $input
     * @return array<string, mixed>
     */
    public function settleManually(User $actor, string $bookingGuid, string $refundGuid, array $input): array
    {
        if ((int) $actor->role < 1) {
            throw new AuthorizationException;
        }
        $reference = strtoupper(trim($input['settlement_reference']));

        $result = DB::transaction(function () use ($actor, $bookingGuid, $refundGuid, $input, $reference): array {
            $ref = DB::table('bookings')->where('guid', $bookingGuid)->first(['destination_quota_guid']) ?? throw new ModelNotFoundException;
            $destination = DB::table('destination_quotas')->where('guid', $ref->destination_quota_guid)->value('destination_guid');
            app(AdminDestinationScopeService::class)->lockDestination($destination);
            $booking = $this->lockBooking($bookingGuid);
            $actor = app(AdminDestinationScopeService::class)->authorize($actor, $destination, true);
            $request = DB::table('booking_refund_requests')->where('guid', $refundGuid)->where('booking_guid', $bookingGuid)->lockForUpdate()->first()
                ?? throw new ModelNotFoundException;
            if ($request->status === 'manual_refunded' && $request->settlement_reference === $reference && (int) $request->settlement_amount === (int) $input['settlement_amount']
                && $request->settled_by_user_guid === $actor->guid) {
                // A retry of the very same settlement after a lost response: return the stored result.
                return ['summary' => $this->summary($request, true)];
            }
            if ($request->status !== 'manual_action_required') {
                throw new BookingException('This refund request is not waiting for manual settlement.', 'refund_not_settleable', ['status' => $request->status]);
            }
            // Current (locking) read: a settlement committed while this call waited for the destination lock must be seen.
            if (DB::table('booking_refund_requests')->where('booking_guid', $bookingGuid)->whereIn('status', self::SETTLED_STATUSES)->sharedLock()->exists()) {
                throw new BookingException('This booking has already been refunded.', 'refund_already_settled');
            }
            if ($this->usedAdmission($bookingGuid)) {
                $this->move($request, 'needs_review', $actor->guid, 'manual_settlement_blocked_used_admission', ['review_reason' => 'used_admission']);

                return ['blocked' => 'ticket_already_used'];
            }
            if ($this->payments->entitlement($booking) !== 'paid') {
                throw new BookingException('The payment was already adjusted by the provider; verify before settling manually.', 'refund_payment_changed');
            }
            if ((int) $input['settlement_amount'] !== (int) $request->amount) {
                throw new BookingException('The settled amount must equal the requested refund amount.', 'refund_amount_mismatch', ['expected_amount' => (int) $request->amount]);
            }
            $settledAt = CarbonImmutable::parse($input['settled_at'])->utc();
            if ($settledAt->isFuture() || $settledAt->lt(CarbonImmutable::parse($request->requested_at, 'UTC'))) {
                throw new BookingException('The settlement time is outside the allowed range.', 'refund_settlement_time_invalid');
            }
            if (DB::table('booking_refund_requests')->where('settlement_reference', $reference)->exists()) {
                throw new BookingException('This transfer reference was already used for another refund.', 'refund_reference_reused');
            }
            $now = now();
            try {
                $this->move($request, 'manual_refunded', $actor->guid, 'manual_settlement_verified', [
                    'active' => null, 'refund_channel' => 'manual', 'settlement_method' => $input['settlement_method'], 'settlement_reference' => $reference,
                    'settlement_amount' => (int) $input['settlement_amount'], 'settled_at' => $settledAt, 'settled_by_user_guid' => $actor->guid,
                    'settlement_note' => trim($input['evidence_note']), 'refunded_at' => $settledAt,
                ]);
            } catch (QueryException $exception) {
                if (! DB::table('booking_refund_requests')->where('settlement_reference', $reference)->exists()) {
                    throw $exception;
                }
                throw new BookingException('This transfer reference was already used for another refund.', 'refund_reference_reused');
            }
            // Settlement meets the verified policy: only now does the admission credential stop working (unused units only).
            DB::table('booking_tickets')->where('booking_guid', $bookingGuid)->whereNull('checked_in_at')->whereNotNull('qr_token_hash')
                ->whereNull('qr_revoked_at')->update(['qr_revoked_at' => $now, 'updated_at' => $now]);

            return ['summary' => $this->summary(DB::table('booking_refund_requests')->where('guid', $refundGuid)->first(), true)];
        }, 3);

        if (isset($result['blocked'])) {
            throw new BookingException('A ticket for this booking has already been used; the request was sent to review.', $result['blocked']);
        }

        return $result['summary'];
    }

    /**
     * Called by the payment engine only after a verified provider refund/reversal for the applied attempt was applied.
     * A booking whose admission was already used is held for review instead of being reported as refunded.
     */
    public function recordVerifiedAdjustment(string $bookingGuid, string $attemptGuid, string $eventGuid, string $eventType = 'refund'): void
    {
        $settled = DB::table('booking_refund_requests')->where('booking_guid', $bookingGuid)->where('payment_attempt_guid', $attemptGuid)
            ->where('status', 'manual_refunded')->lockForUpdate()->first();
        if ($settled) {
            // The customer was already repaid manually: the provider event stays in the ledger untouched, and a human must reclaim it.
            DB::table('booking_refund_requests')->where('guid', $settled->guid)->update(['review_reason' => 'duplicate_refund_detected', 'provider_correlation' => $eventGuid, 'updated_at' => now()]);
            $this->audit($settled->guid, null, 'manual_refunded', 'manual_refunded', 'duplicate_refund_detected');
            Log::error('Duplicate refund detected: provider refund after manual settlement.', ['refund_request_guid' => $settled->guid, 'booking_guid' => $bookingGuid, 'payment_event_guid' => $eventGuid]);

            return;
        }
        // Only an adjustment of the very attempt the request was filed against counts; a late duplicate attempt must not close it.
        $request = DB::table('booking_refund_requests')->where('booking_guid', $bookingGuid)->where('payment_attempt_guid', $attemptGuid)
            ->where('active', 1)->lockForUpdate()->first();
        if (! $request) {
            return;
        }
        if ($eventType !== 'refund') {
            // A provider-initiated reversal/chargeback is not the refund the customer asked for: a human confirms the outcome.
            $this->move($request, 'needs_review', null, 'provider_reversal', ['review_reason' => 'provider_reversal', 'provider_correlation' => $eventGuid]);

            return;
        }
        if ($this->usedAdmission($bookingGuid)) {
            $this->move($request, 'needs_review', null, 'verified_adjustment_after_use', ['review_reason' => 'used_admission', 'provider_correlation' => $eventGuid]);

            return;
        }
        // Only a verified full refund ends the temporary hold, and it does so by revoking the admission credential for good.
        $this->move($request, 'refunded', null, 'verified_provider_adjustment', ['active' => null, 'refunded_at' => now(), 'provider_correlation' => $eventGuid, 'admission_hold' => null]);
        DB::table('booking_tickets')->where('booking_guid', $bookingGuid)->whereNull('checked_in_at')->whereNotNull('qr_token_hash')
            ->whereNull('qr_revoked_at')->update(['qr_revoked_at' => now(), 'updated_at' => now()]);
    }

    // -----------------------------------------------------------------------------------------------------------------
    // Provider API refund (Midtrans QRIS): claim, dispatch with one persisted key, verification, recovery
    // -----------------------------------------------------------------------------------------------------------------

    public static function refundKey(string $refundGuid): string
    {
        return 'PAMI-RF-'.$refundGuid;
    }

    /** Why the provider API cannot take this refund (null when it can); the request then follows the manual workflow. */
    private function providerRefundUnavailable(?object $intent, ?object $attempt): ?string
    {
        if (! $intent || ! $attempt || $intent->provider !== 'midtrans') {
            return 'provider_not_supported';
        }

        return app(MidtransClient::class)->refundReason($attempt);
    }

    private function queueProviderRefund(string $refundGuid): void
    {
        try {
            ProcessMidtransRefund::dispatch($refundGuid);
        } catch (Throwable) {
            // The claim is durable; the scheduler dispatches it.
            Log::warning('Provider refund awaits scheduled dispatch.', ['refund_request_guid' => $refundGuid]);
        }
    }

    /**
     * One worker pass over a claimed provider refund. Returns skipped (not claimable now), released, review, pending, sent or failed.
     *
     * First pass: re-check the ticket and the capability under the domain locks, record that the provider may now have received the
     * request, and send it. Later passes: the persisted key is only ever re-sent; an accepted refund is verified by the Status API
     * and never sent again. A timeout, an ambiguous answer or a crash can therefore never produce a second key.
     */
    public function processProviderRefund(string $refundGuid): string
    {
        $claimed = DB::table('booking_refund_requests')->where('guid', $refundGuid)->where('refund_route', 'provider_api')->where('status', 'refund_pending')
            ->where(fn ($lease) => $lease->whereNull('dispatch_claimed_until')->orWhere('dispatch_claimed_until', '<=', now()))
            ->update(['dispatch_claimed_until' => now()->addSeconds(self::LEASE_SECONDS), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return 'skipped';
        }
        try {
            return $this->advanceProviderRefund($refundGuid);
        } catch (Throwable $exception) {
            Log::error('Provider refund step failed; it will be retried with the same key.', ['refund_request_guid' => $refundGuid, 'exception_class' => $exception::class]);
            DB::table('booking_refund_requests')->where('guid', $refundGuid)->where('status', 'refund_pending')
                ->update(['last_error_code' => 'internal_error', 'next_reconcile_at' => now()->addMinute()]);

            return 'failed';
        } finally {
            DB::table('booking_refund_requests')->where('guid', $refundGuid)->update(['dispatch_claimed_until' => null]);
        }
    }

    private function advanceProviderRefund(string $refundGuid): string
    {
        $plan = $this->inRefundDomain($refundGuid, function (object $booking, ?object $intent, ?object $attempt, object $request): string {
            if ($request->status !== 'refund_pending' || $request->refund_route !== 'provider_api' || ! $intent || ! $attempt) {
                return 'skip';
            }
            if ($this->usedAdmission($booking->guid)) {
                $this->move($request, 'needs_review', null, 'provider_refund_blocked_used_admission', ['review_reason' => 'used_admission']);

                return 'review';
            }
            $first = $request->dispatch_started_at === null;
            if ($first && ! $this->paymentStillSettled($intent, $attempt)) {
                // The locked payment rows no longer show one settled payment (a review or reversal intervened): never send money back
                // on a payment that is under review. The admission hold stays.
                $this->move($request, 'needs_review', null, 'provider_refund_blocked_payment_state', ['review_reason' => 'payment_state_changed',
                    'escalated_at' => now(), 'next_reconcile_at' => null]);

                return 'review';
            }
            $unavailable = app(MidtransClient::class)->refundReason($attempt);
            if ($first && $unavailable !== null) {
                // Nothing was sent yet, so the manual workflow is safe. After the first send this branch can never be taken.
                $this->move($request, 'manual_action_required', null, 'provider_refund_unavailable:'.$unavailable,
                    ['refund_route' => 'manual', 'admission_hold' => null, 'next_reconcile_at' => null, 'last_error_code' => substr($unavailable, 0, 64)]);

                return 'released';
            }
            if ($first) {
                DB::table('booking_refund_requests')->where('guid', $request->guid)->update(['dispatch_started_at' => now(), 'dispatch_attempts' => 1, 'updated_at' => now()]);

                return 'send';
            }
            if ($request->provider_refund_reference !== null) {
                return 'verify';
            }

            return $unavailable === null && (int) $request->dispatch_attempts < self::DISPATCH_MAX ? 'verify_then_send' : 'verify';
        });
        if ($plan === null || $plan === 'skip') {
            return 'skipped';
        }
        if (in_array($plan, ['released', 'review'], true)) {
            return $plan;
        }
        if (in_array($plan, ['verify', 'verify_then_send'], true)) {
            $request = DB::table('booking_refund_requests')->where('guid', $refundGuid)->first();
            app(MidtransPaymentService::class)->verifyRefund($request->payment_attempt_guid);
            $request = DB::table('booking_refund_requests')->where('guid', $refundGuid)->first();
            if ($request->status !== 'refund_pending') {
                return 'verified';
            }
            if ($plan === 'verify' || $request->provider_refund_reference !== null) {
                return $this->scheduleVerification($refundGuid);
            }
            DB::table('booking_refund_requests')->where('guid', $refundGuid)->update(['dispatch_attempts' => DB::raw('dispatch_attempts + 1'), 'updated_at' => now()]);
        }

        return $this->sendProviderRefund($refundGuid);
    }

    private function sendProviderRefund(string $refundGuid): string
    {
        $request = DB::table('booking_refund_requests')->where('guid', $refundGuid)->first();
        $attempt = DB::table('payment_attempts')->where('guid', $request->payment_attempt_guid)->first();
        try {
            $result = app(MidtransClient::class)->refund($attempt, $request);
        } catch (Throwable $exception) {
            Log::warning('Midtrans refund raised an internal fault.', ['refund_request_guid' => $refundGuid, 'exception_class' => $exception::class]);
            $result = ['status' => 'unknown', 'reason' => 'provider_unavailable'];
        }
        $outcome = $this->inRefundDomain($refundGuid, function (object $booking, ?object $intent, ?object $attempt, object $request) use ($result): string {
            if ($request->status !== 'refund_pending') {
                return 'settled_elsewhere';
            }
            switch ($result['status']) {
                case 'accepted':
                    DB::table('booking_refund_requests')->where('guid', $request->guid)->update(['provider_refund_reference' => $result['provider_refund_reference'],
                        'last_error_code' => null, 'next_reconcile_at' => now()->addSeconds(30), 'updated_at' => now()]);
                    $this->audit($request->guid, null, 'refund_pending', 'refund_pending', 'provider_refund_accepted');

                    return 'sent';
                case 'rejected':
                    if ((int) $request->dispatch_attempts <= 1) {
                        // The money path is only abandoned after the Status API confirms that nothing was refunded (see below).
                        return 'verify_rejection';
                    }
                    $this->move($request, 'needs_review', null, 'provider_refund_rejected_after_retry', ['review_reason' => 'provider_refund_rejected',
                        'last_error_code' => substr($result['reason'], 0, 64), 'escalated_at' => now(), 'next_reconcile_at' => null]);

                    return 'review';
                case 'review':
                    // Not the full refund we asked for: the request is quarantined in the same step as the payment (never re-sent).
                    $this->move($request, 'needs_review', null, 'provider_refund_partial_answer', ['review_reason' => 'partial_adjustment_review',
                        'escalated_at' => now(), 'next_reconcile_at' => null]);

                    return 'quarantine';
                default:
                    if ((int) $request->dispatch_attempts >= self::DISPATCH_MAX) {
                        $this->move($request, 'needs_review', null, 'refund_dispatch_unresolved', ['review_reason' => 'refund_dispatch_unresolved',
                            'last_error_code' => substr((string) ($result['reason'] ?? 'unknown'), 0, 64), 'escalated_at' => now(), 'next_reconcile_at' => null]);

                        return 'review';
                    }
                    DB::table('booking_refund_requests')->where('guid', $request->guid)->update(['last_error_code' => substr((string) ($result['reason'] ?? 'unknown'), 0, 64),
                        'next_reconcile_at' => now()->addSeconds($this->backoff((int) $request->dispatch_attempts)), 'updated_at' => now()]);

                    return 'pending';
            }
        });
        if ($outcome === 'verify_rejection') {
            // A refusal is not proof that nothing moved (a dashboard refund or a lost earlier answer may exist), so the live provider
            // state decides: only a verified, still-paid payment lets the request leave the provider route for the manual workflow.
            $verified = app(MidtransPaymentService::class)->verifyRefund($request->payment_attempt_guid);

            return $this->inRefundDomain($refundGuid, function (object $booking, ?object $intent, ?object $attempt, object $request) use ($result, $verified): string {
                if ($request->status !== 'refund_pending') {
                    return 'settled_elsewhere';
                }
                if ($verified === 'done' && $intent && $attempt && $intent->status === 'paid' && $attempt->status === 'paid') {
                    $this->move($request, 'manual_action_required', null, 'provider_refund_rejected', ['refund_route' => 'manual', 'admission_hold' => null,
                        'review_reason' => 'provider_refund_rejected', 'last_error_code' => substr($result['reason'], 0, 64), 'next_reconcile_at' => null]);

                    return 'released';
                }
                // Unverified: keep the hold and the key; the next pass verifies first and, if still needed, re-sends the SAME key.
                DB::table('booking_refund_requests')->where('guid', $request->guid)->update(['last_error_code' => 'provider_refund_rejected_unverified',
                    'next_reconcile_at' => now()->addSeconds($this->backoff((int) $request->dispatch_attempts)), 'updated_at' => now()]);

                return 'pending';
            }) ?? 'skipped';
        }
        if ($outcome === 'quarantine') {
            // The provider answered with something other than the full refund: nothing is final, the payment goes to review.
            app(PaymentSettlementService::class)->flagAmbiguousProviderOutcome($request->payment_attempt_guid, 'partial_adjustment_review');
            Log::error('Midtrans refund answer was not the full refund; payment quarantined.', ['refund_request_guid' => $refundGuid]);

            return 'review';
        }

        return $outcome ?? 'skipped';
    }

    /** Verification pass that did not finish the refund: count it, back off, and escalate once the bound is reached. */
    private function scheduleVerification(string $refundGuid): string
    {
        return $this->inRefundDomain($refundGuid, function (object $booking, ?object $intent, ?object $attempt, object $request): string {
            if ($request->status !== 'refund_pending') {
                return 'verified';
            }
            $count = (int) $request->reconcile_count + 1;
            if ($count >= self::RECONCILE_MAX) {
                $this->move($request, 'needs_review', null, 'refund_unverified', ['review_reason' => 'refund_unverified', 'reconcile_count' => $count,
                    'last_reconciled_at' => now(), 'escalated_at' => now(), 'next_reconcile_at' => null]);
                Log::error('Provider refund could not be verified; escalated to review.', ['refund_request_guid' => $request->guid, 'reconcile_count' => $count]);

                return 'review';
            }
            DB::table('booking_refund_requests')->where('guid', $request->guid)->update(['reconcile_count' => $count, 'last_reconciled_at' => now(),
                'next_reconcile_at' => now()->addSeconds($this->backoff($count)), 'updated_at' => now()]);

            return 'pending';
        }) ?? 'skipped';
    }

    private function backoff(int $round): int
    {
        return min(900, 30 * 2 ** min(max($round, 0), 5)) + random_int(0, 15);
    }

    /**
     * Runs a callback under the domain locks in the plan's order: destination, quota, booking, intent, attempt, refund request.
     *
     * @template T
     *
     * @param  \Closure(object, ?object, ?object, object): T  $callback
     * @return T|null
     */
    private function inRefundDomain(string $refundGuid, \Closure $callback): mixed
    {
        $reference = DB::table('booking_refund_requests as r')->join('bookings as b', 'b.guid', '=', 'r.booking_guid')
            ->where('r.guid', $refundGuid)->first(['b.guid as booking_guid', 'b.destination_quota_guid', 'r.payment_attempt_guid']);
        if (! $reference) {
            return null;
        }
        $destination = DB::table('destination_quotas')->where('guid', $reference->destination_quota_guid)->value('destination_guid');

        return DB::transaction(function () use ($reference, $destination, $refundGuid, $callback): mixed {
            app(AdminDestinationScopeService::class)->lockDestination($destination);
            $booking = $this->lockBooking($reference->booking_guid);
            $intent = DB::table('payment_intents')->where('booking_guid', $booking->guid)->lockForUpdate()->first();
            $attempt = DB::table('payment_attempts')->where('guid', $reference->payment_attempt_guid)->lockForUpdate()->first();
            $request = DB::table('booking_refund_requests')->where('guid', $refundGuid)->lockForUpdate()->first();

            return $request ? $callback($booking, $intent, $attempt, $request) : null;
        }, 3);
    }

    /** A provider refund/reversal that could not be applied cleanly (amount, snapshot, ownership) must be reviewed by a human. */
    public function flagForReview(string $bookingGuid, string $reason): void
    {
        $request = DB::table('booking_refund_requests')->where('booking_guid', $bookingGuid)->where('active', 1)->lockForUpdate()->first();
        if ($request && $request->status !== 'needs_review') {
            $this->move($request, 'needs_review', null, $reason, ['review_reason' => substr($reason, 0, 64)]);
        }
    }

    /** @return array{data: list<array<string, mixed>>} */
    public function listFor(User $user, string $bookingGuid): array
    {
        $staff = (int) $user->role >= 1;
        $query = DB::table('booking_refund_requests')->where('booking_guid', $bookingGuid);
        if (! $staff) {
            $this->paymentService->ownerBooking($user, $bookingGuid);
        } else {
            $scoped = DB::table('bookings')->join('destination_quotas', 'destination_quotas.guid', '=', 'bookings.destination_quota_guid')->where('bookings.guid', $bookingGuid);
            abort_unless(app(AdminDestinationScopeService::class)->apply($scoped, $user, 'destination_quotas.destination_guid')->exists(), 404);
        }

        return ['refund_requests' => $query->orderByDesc('requested_at')->get()->map(fn (object $row): array => $this->summary($row, $staff))->all()];
    }

    /** Latest request, for the owner payment contract. @return array<string, mixed>|null */
    public function latestSummary(string $bookingGuid): ?array
    {
        $row = DB::table('booking_refund_requests')->where('booking_guid', $bookingGuid)->orderByDesc('requested_at')->orderByDesc('created_at')->first();

        return $row ? $this->summary($row) : null;
    }

    /** @return array<string, mixed> */
    private function summary(object $row, bool $staff = false): array
    {
        $iso = fn (?string $value): ?string => $value ? CarbonImmutable::parse($value, 'UTC')->toIso8601String() : null;
        $summary = [
            'guid' => $row->guid, 'booking_guid' => $row->booking_guid, 'status' => $row->status, 'message' => $this->message($row->status),
            'amount' => (int) $row->amount, 'currency' => $row->currency, 'requested_at' => $iso($row->requested_at), 'decided_at' => $iso($row->decided_at),
            'refunded_at' => $iso($row->refunded_at), 'reason' => $row->reason,
            'refund_channel' => $row->status === 'manual_refunded' ? 'manual' : ($row->status === 'refunded' ? 'provider' : null),
            'settled_at' => $iso($row->settled_at),
            'settlement_method' => $row->settlement_method, 'settlement_reference' => $this->maskReference($row->settlement_reference),
            // Staff notes are shown to the owner only when they explain a rejection.
            'decision_note' => $row->status === 'rejected' || $staff ? $row->decision_note : null,
        ];
        if ($staff) {
            $summary += ['refund_route' => $row->refund_route, 'refund_key' => $row->refund_key, 'provider_refund_reference' => $row->provider_refund_reference,
                'admission_hold' => $row->admission_hold !== null, 'last_error_code' => $row->last_error_code, 'escalated_at' => $iso($row->escalated_at)];
            $summary += ['review_reason' => $row->review_reason, 'provider_correlation' => $row->provider_correlation, 'requested_by_user_guid' => $row->requested_by_user_guid,
                'settlement_amount' => $row->settlement_amount === null ? null : (int) $row->settlement_amount, 'settled_by_user_guid' => $row->settled_by_user_guid,
                'settlement_note' => $row->settlement_note];
            $summary['settlement_reference'] = $row->settlement_reference;
        }

        return $summary;
    }

    private function message(string $status): string
    {
        return match ($status) {
            'requested' => 'Permintaan pengembalian dana sedang ditinjau pengelola.',
            'rejected' => 'Permintaan pengembalian dana ditolak.',
            'approved', 'manual_action_required' => 'Permintaan disetujui. Pengembalian dana diproses manual oleh pengelola dan belum dianggap selesai sampai pembayaran kembali terverifikasi.',
            'refund_pending' => 'Pengembalian dana sedang diproses. Status berubah setelah terverifikasi.',
            'needs_review' => 'Permintaan memerlukan peninjauan pengelola.',
            'refunded' => 'Pengembalian dana terverifikasi.',
            'manual_refunded' => 'Pengembalian dana telah ditransfer manual oleh pengelola dan diverifikasi. Ini bukan refund otomatis dari penyedia pembayaran.',
            default => 'Status tidak diketahui.',
        };
    }

    private function maskReference(?string $reference): ?string
    {
        return $reference === null ? null : str_repeat('*', max(0, strlen($reference) - 4)).substr($reference, -4);
    }

    private function lockBooking(string $guid): object
    {
        return app(TicketService::class)->booking($guid, true);
    }

    /** From the LOCKED rows: one paid attempt that the intent actually applied. Rows are absent only for legacy flows that never had them. */
    private function paymentStillSettled(?object $intent, ?object $attempt): bool
    {
        return ! $intent || ! $attempt
            || ($intent->status === 'paid' && $attempt->status === 'paid' && $intent->applied_attempt_guid === $attempt->guid);
    }

    private function usedAdmission(string $bookingGuid): bool
    {
        return DB::table('booking_tickets')->where('booking_guid', $bookingGuid)->whereNotNull('checked_in_at')->lockForUpdate()->exists()
            || DB::table('check_ins')->where('booking_guid', $bookingGuid)->exists();
    }

    /** @param array<string, mixed> $changes */
    private function move(object $request, string $to, ?string $actorGuid, ?string $note, array $changes): void
    {
        DB::table('booking_refund_requests')->where('guid', $request->guid)->update($changes + ['status' => $to, 'updated_at' => now()]);
        $this->audit($request->guid, $actorGuid, $request->status, $to, $note);
    }

    private function audit(string $requestGuid, ?string $actorGuid, ?string $from, string $to, ?string $note): void
    {
        DB::table('booking_refund_request_events')->insert(['guid' => (string) Str::uuid(), 'refund_request_guid' => $requestGuid, 'actor_guid' => $actorGuid,
            'from_status' => $from, 'to_status' => $to, 'note' => $note, 'created_at' => now()]);
    }
}
