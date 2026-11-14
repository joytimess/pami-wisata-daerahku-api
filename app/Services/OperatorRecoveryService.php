<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Jobs\ProcessMidtransRefund;
use App\Models\User;
use App\Support\BookingRules;
use App\Support\PaymentStates;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Authorized operator recovery for payments, unknown Charge outcomes and refunds the automatic engine can no longer move.
 *
 * Operating rules, all enforced here and not in the client:
 * - An operator never writes `paid`, `refunded` or a terminal-unpaid outcome on an assumption, a timeout or a provider 404. Money-
 *   moving outcomes need evidence the SERVER verified itself (a fresh authenticated Status lookup and the strict ledger read); the
 *   operator's evidence only corroborates it and is stored beside the decision.
 * - Every call is audited in `payment_recovery_actions`: who, what, which evidence, which verified facts, and what was refused.
 * - Insufficient evidence changes nothing: the investigation status, the quota hold and the admission hold stay as they were.
 * - Applied actions are idempotent per actor and key; locks follow the engine's order (destination, quota, booking, intent,
 *   attempt, refund request, tickets) so a recovery can never wait on a settlement, expiry, refund or check-in in a cycle.
 */
class OperatorRecoveryService
{
    /** Actions that decide a money outcome: superadmin only. */
    private const SUPERADMIN_ACTIONS = ['confirm_paid', 'close_unpaid'];

    /** Review reasons whose cause an operator can resolve with provider-side action; identity and ledger conflicts are engineering cases. */
    private const CONFIRMABLE_REASONS = [null, 'payment_not_applicable', 'fraud_status_review', 'provider_response_invalid', 'provider_expiry_invalid'];

    private const CLOSABLE_REASONS = [null, 'fraud_status_review', 'provider_response_invalid', 'provider_expiry_invalid'];

    private const RESUMABLE_REFUND_REASONS = ['refund_dispatch_unresolved', 'refund_unverified', 'refund_configuration_unavailable', 'provider_refund_rejected'];

    private const OPEN_ATTEMPT_STATUSES = ['initiating', 'pending', 'unknown'];

    /** A lookup counts as fresh evidence for this long. */
    private const FRESH_SECONDS = 180;

    public function __construct(private TicketService $tickets, private AdminDestinationScopeService $scope, private PaymentSettlementService $settlement,
        private PaymentEligibility $payments, private BookingRefundService $refunds) {}

    // -----------------------------------------------------------------------------------------------------------------
    // Payments and attempts
    // -----------------------------------------------------------------------------------------------------------------

    /**
     * @param  array{action: string, idempotency_key: string, attempt_guid?: ?string, evidence_type?: ?string, evidence_reference?: ?string, evidence_note?: ?string}  $input
     * @return array<string, mixed>
     */
    public function recoverPayment(User $actor, string $bookingGuid, array $input): array
    {
        [$actor, $ref] = $this->context($actor, $bookingGuid);
        $action = $input['action'];
        $this->assertRole($actor, $action);
        $hash = $this->hash($bookingGuid, $input['attempt_guid'] ?? null, $input);
        if ($stored = $this->storedAction($actor->guid, $input['idempotency_key'], $hash)) {
            return $this->summary($bookingGuid, $stored);
        }
        $lookups = $this->gatherAttemptEvidence($bookingGuid, $action, $input['attempt_guid'] ?? null);
        try {
            $result = DB::transaction(fn (): array => $this->decidePayment($actor, $ref, $bookingGuid, $input, $hash, $lookups), 3);
        } catch (UniqueConstraintViolationException) {
            throw new BookingException('Idempotency key already used with different input.', 'idempotency_conflict');
        } catch (DomainException $exception) {
            // The strict ledger read did not agree with the change: it was rolled back, and the refusal is audited on its own.
            $audit = $this->audit($bookingGuid, 'payment_intent', $input['attempt_guid'] ?? $bookingGuid, $actor, $input, 'refused', 'ledger_binding_failed', ['detail' => $exception->getMessage()], null, null, null, $hash);
            throw new BookingException('The verified ledger does not support this decision.', 'insufficient_evidence', ['reasons' => ['ledger_binding_failed'], 'audit_guid' => $audit]);
        }
        if ($result['replay'] ?? false) {
            return $this->summary($bookingGuid, $result['audit_row']);
        }
        if ($result['refusal']) {
            throw new BookingException('The evidence is not sufficient for this decision.', 'insufficient_evidence', ['reasons' => $result['refusal'], 'audit_guid' => $result['audit']]);
        }
        if ($action === 'close_unpaid') {
            // The attempts are now proven terminal: closing the booking and releasing its quota is the engine's own atomic step.
            // An operator's decision to close ends the booking; it is never turned into a fresh payment cycle.
            $this->settlement->closeAfterOutcome($bookingGuid, false);
        }

        return $this->summary($bookingGuid, DB::table('payment_recovery_actions')->where('guid', $result['audit'])->first());
    }

    /**
     * Provider evidence is gathered OUTSIDE any transaction (it is a network call) and is applied by the engine's own claimed lookup.
     * The decision that follows re-reads everything under locks and trusts none of it blindly.
     *
     * @return array<string, string> attempt guid => done|throttled|skipped|failed
     */
    private function gatherAttemptEvidence(string $bookingGuid, string $action, ?string $attemptGuid): array
    {
        if (! in_array($action, ['reverify', 'confirm_paid', 'close_unpaid'], true)) {
            return [];
        }
        $attempts = DB::table('payment_attempts as a')->join('payment_intents as i', 'i.guid', '=', 'a.payment_intent_guid')
            ->where('i.booking_guid', $bookingGuid)->where('a.provider', 'midtrans')->orderByDesc('a.attempt_number')->get(['a.guid', 'a.status']);
        if ($attemptGuid !== null) {
            abort_unless($attempts->contains('guid', $attemptGuid), 404);
            $targets = [$attemptGuid];
        } else {
            $targets = match ($action) {
                'reverify' => $attempts->where('status', '!=', 'reversed')->pluck('guid')->take(5)->all(),
                'close_unpaid' => $attempts->whereIn('status', self::OPEN_ATTEMPT_STATUSES)->pluck('guid')->take(5)->all(),
                default => [],
            };
        }
        $results = [];
        foreach ($targets as $guid) {
            $results[$guid] = $this->verify($guid);
        }

        return $results;
    }

    /**
     * @param  array<string, string>  $lookups
     * @return array<string, mixed>
     */
    private function decidePayment(User $actor, object $ref, string $bookingGuid, array $input, string $hash, array $lookups): array
    {
        $this->scope->lockDestination($ref->destination_guid);
        $booking = $this->tickets->booking($bookingGuid, true);
        $actor = $this->scope->authorize($actor, $ref->destination_guid, true);
        $action = $input['action'];
        $this->assertRole($actor, $action);
        if ($stored = $this->storedAction($actor->guid, $input['idempotency_key'], $hash, true)) {
            return ['replay' => true, 'audit_row' => $stored];
        }
        $intent = DB::table('payment_intents')->where('booking_guid', $bookingGuid)->lockForUpdate()->first();
        $attempts = $intent ? DB::table('payment_attempts')->where('payment_intent_guid', $intent->guid)->orderBy('attempt_number')->lockForUpdate()->get() : collect();
        $subject = $input['attempt_guid'] ?? null;
        abort_if($subject !== null && ! $attempts->contains('guid', $subject), 404);
        $decision = match ($action) {
            'reverify' => ['facts' => ['lookups' => $lookups]],
            'note' => ['facts' => []],
            'confirm_paid' => $this->confirmPaid($actor, $input, $booking, $intent, $attempts, $lookups),
            'close_unpaid' => $this->closeUnpaid($actor, $input, $booking, $intent, $attempts, $lookups),
        };
        $refusal = $decision['refusal'] ?? null;
        $audit = $this->audit($bookingGuid, $subject !== null ? 'payment_attempt' : 'payment_intent', $subject ?? ($intent->guid ?? $bookingGuid), $actor, $input,
            $refusal ? 'refused' : 'applied', $refusal ? implode(',', $refusal) : null, $decision['facts'] + ($refusal ? ['reasons' => $refusal] : []),
            $decision['from'] ?? null, $decision['to'] ?? null, $refusal ? null : $input['idempotency_key'], $hash);

        return ['replay' => false, 'audit' => $audit, 'refusal' => $refusal];
    }

    /**
     * Confirms a reviewed payment as paid, and only from a verified capture the engine already holds: bound to the attempt, within
     * the one deadline, never followed by a provider adjustment, never beside a settled refund or a competing paid attempt.
     *
     * @return array<string, mixed>
     */
    private function confirmPaid(User $actor, array $input, object $booking, ?object $intent, $attempts, array $lookups): array
    {
        $reasons = [];
        $target = $attempts->firstWhere('guid', $input['attempt_guid']);
        if (! $intent || ! $target) {
            return ['refusal' => ['payment_not_found'], 'facts' => []];
        }
        $from = $intent->status;
        if ($intent->provider !== 'midtrans') {
            $reasons[] = 'provider_recovery_unsupported';
        }
        if ($intent->status !== 'needs_review') {
            $reasons[] = 'intent_not_in_review';
        } elseif (! in_array($intent->review_reason, self::CONFIRMABLE_REASONS, true)) {
            $reasons[] = 'review_reason_not_resolvable:'.$intent->review_reason;
        }
        if ($intent->applied_attempt_guid !== null) {
            $reasons[] = 'already_applied';
        }
        // The decision rests on a lookup completed in this very request; a failed, skipped or throttled one proves nothing, and any
        // ambiguity the provider showed (chargeback, partial refund, identity drift) blocks the confirmation.
        if (($lookups[$target->guid] ?? null) !== 'done:paid') {
            $reasons[] = 'fresh_lookup_not_completed';
        }
        if (in_array($target->last_error_code, MidtransPaymentService::REVIEW_REASONS, true)) {
            $reasons[] = 'provider_ambiguity_recorded';
        }
        if (app()->environment('production') && $intent->environment !== 'production') {
            $reasons[] = 'sandbox_payment_on_production';
        }
        if (! DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'awaiting_payment')->exists()) {
            $reasons[] = 'approval_history_missing';
        }
        if ($target->status !== 'paid' || $target->verified_paid_at === null || $target->reversed_at !== null) {
            $reasons[] = 'attempt_not_verified_paid';
        }
        if ($attempts->where('status', 'paid')->where('guid', '!=', $target->guid)->isNotEmpty()) {
            $reasons[] = 'competing_paid_attempt';
        }
        if ((int) $target->attempt_number < (int) $attempts->max('attempt_number')) {
            $reasons[] = 'newer_attempt_exists';
        }
        if (! is_string($target->provider_reference) || ! hash_equals($target->provider_reference, (string) ($input['evidence_reference'] ?? ''))) {
            $reasons[] = 'evidence_reference_mismatch';
        }
        if (! in_array($booking->status, ['awaiting_payment', 'payment_verification', 'payment_review'], true)) {
            $reasons[] = 'booking_not_open';
        }
        if (DB::table('booking_refund_requests')->where('booking_guid', $booking->guid)->whereIn('status', BookingRefundService::SETTLED_STATUSES)->lockForUpdate()->exists()) {
            $reasons[] = 'refund_already_settled';
        }
        if (DB::table('payment_events')->where('payment_intent_guid', $intent->guid)->where('verification', 'verified')->whereIn('event_type', ['refund', 'reversal'])->exists()) {
            $reasons[] = 'provider_adjustment_exists';
        }
        $event = DB::table('payment_events')->where('payment_attempt_guid', $target->guid)->where('event_type', 'capture')->where('verification', 'verified')
            ->where('disposition', 'unapplied')->where('provider_occurred_at', $target->verified_paid_at)->lockForUpdate()->first();
        if (! $event) {
            $reasons[] = 'verified_capture_missing';
        } else {
            $occurred = CarbonImmutable::parse($event->provider_occurred_at, 'UTC');
            if ($occurred->gte(CarbonImmutable::parse($booking->payment_expires_at, 'UTC')) || $occurred->gte(CarbonImmutable::parse($target->instruction_expires_at, 'UTC'))
                || $occurred->lt(CarbonImmutable::parse($target->started_at, 'UTC'))) {
                $reasons[] = 'capture_outside_window';
            }
        }
        if (! now()->lt(BookingRules::visitStart($booking->visit_date, $booking->destination_timezone))) {
            $reasons[] = 'visit_window_closed';
        }
        if ($reasons) {
            return ['refusal' => $reasons, 'facts' => ['review_reason' => $intent->review_reason, 'attempt_status' => $target->status], 'from' => $from];
        }
        PaymentStates::assertOperatorIntentTransition('needs_review', 'paid');
        $this->settlement->applyVerifiedCapture($booking, $intent, $target, $event, $actor->guid, ' Confirmed by operator after review.');
        // The review hold revoked the unused entry units; a confirmed payment hands them back (no refund is settled, checked above).
        DB::table('booking_tickets')->where('booking_guid', $booking->guid)->whereNull('checked_in_at')->whereNotNull('qr_token_hash')
            ->whereNotNull('qr_revoked_at')->update(['qr_revoked_at' => null, 'updated_at' => now()]);
        $fresh = $this->tickets->booking($booking->guid, true);
        if ($this->payments->entitlement($fresh) !== 'paid') {
            throw new DomainException('Entitlement is not paid after the confirmation.');
        }

        return ['facts' => ['capture_event_guid' => $event->guid, 'review_reason' => $intent->review_reason, 'provider_occurred_at' => (string) $event->provider_occurred_at],
            'from' => $from, 'to' => 'paid'];
    }

    /**
     * Closes a payment as terminal unpaid. Every attempt must be proven terminal (expired/failed by verified evidence) or be the one
     * attempt whose Charge never produced a provider order: absent in a fresh authenticated lookup AND past the internal grace AND
     * corroborated by dashboard evidence. A timeout or a 404 alone never closes anything.
     *
     * @param  array<string, string>  $lookups
     * @return array<string, mixed>
     */
    private function closeUnpaid(User $actor, array $input, object $booking, ?object $intent, $attempts, array $lookups): array
    {
        if (! $intent) {
            return ['refusal' => ['payment_not_found'], 'facts' => []];
        }
        $reasons = [];
        $targetGuid = $input['attempt_guid'] ?? null;
        $target = $targetGuid !== null ? $attempts->firstWhere('guid', $targetGuid) : null;
        if ($targetGuid !== null && ! $target) {
            return ['refusal' => ['payment_not_found'], 'facts' => []];
        }
        $facts = ['lookups' => $lookups];
        if ($intent->provider !== 'midtrans') {
            $reasons[] = 'provider_recovery_unsupported';
        }
        if (! in_array($booking->status, ['awaiting_payment', 'payment_verification', 'payment_review'], true)) {
            $reasons[] = 'booking_not_open';
        }
        if ($intent->status === 'needs_review') {
            if (! in_array($intent->review_reason, self::CLOSABLE_REASONS, true)) {
                $reasons[] = 'review_reason_not_resolvable:'.$intent->review_reason;
            }
        } elseif (! in_array($intent->status, ['requires_payment', 'processing'], true) || $target === null) {
            $reasons[] = 'nothing_to_close';
        }
        if ($target) {
            $reasons = [...$reasons, ...$this->absentOrderReasons($input, $booking, $target, $lookups[$target->guid] ?? null)];
        }
        foreach ($attempts->where('guid', '!=', $target?->guid) as $other) {
            if (! in_array($other->status, ['failed', 'expired'], true)) {
                $reasons[] = 'attempt_not_terminal_unpaid:'.$other->status;
            }
        }
        if ($reasons) {
            return ['refusal' => array_values(array_unique($reasons)), 'facts' => $facts, 'from' => $intent->status];
        }
        if ($target) {
            DB::table('payment_attempts')->where('guid', $target->guid)->update(['status' => 'failed', 'finished_at' => now(), 'next_reconcile_at' => null,
                'last_error_code' => 'operator_closed_no_provider_order', 'updated_at' => now()]);
            $facts['closed_attempt_guid'] = $target->guid;
        }
        if ($intent->status === 'needs_review') {
            PaymentStates::assertOperatorIntentTransition('needs_review', 'requires_payment');
            DB::table('payment_intents')->where('guid', $intent->guid)->update(['status' => 'requires_payment', 'review_reason' => null, 'updated_at' => now()]);
        } elseif ($intent->status === 'processing' && $attempts->where('attempt_number', '>', (int) $target->attempt_number)->isEmpty()) {
            DB::table('payment_intents')->where('guid', $intent->guid)->update(['status' => 'requires_payment', 'updated_at' => now()]);
        }

        return ['facts' => $facts, 'from' => $intent->status, 'to' => 'requires_payment'];
    }

    /** @return list<string> reasons the attempt cannot be closed as "no provider order" (empty when it can) */
    private function absentOrderReasons(array $input, object $booking, object $target, ?string $lookup): array
    {
        $reasons = [];
        if (! in_array($target->status, ['initiating', 'unknown'], true)) {
            $reasons[] = 'attempt_not_unresolved';
        }
        if ($target->provider_reference !== null) {
            $reasons[] = 'provider_order_known';
        }
        if (DB::table('payment_events')->where('payment_attempt_guid', $target->guid)->where('verification', 'verified')->exists()) {
            $reasons[] = 'provider_evidence_exists';
        }
        if (! in_array($input['evidence_type'] ?? null, ['provider_dashboard_no_transaction', 'provider_support_confirmation'], true)) {
            $reasons[] = 'evidence_type_not_accepted';
        }
        if ($booking->notification_grace_expires_at === null || now()->lt(CarbonImmutable::parse($booking->notification_grace_expires_at, 'UTC'))) {
            $reasons[] = 'grace_not_elapsed';
        }
        $fresh = $target->last_reconciled_at !== null && CarbonImmutable::parse($target->last_reconciled_at, 'UTC')->gte(now()->subSeconds(self::FRESH_SECONDS));
        if (! str_starts_with((string) $lookup, 'failed:') || $target->last_error_code !== 'provider_order_not_found' || ! $fresh) {
            $reasons[] = 'order_absence_not_verified';
        }

        return $reasons;
    }

    // -----------------------------------------------------------------------------------------------------------------
    // Refund requests and their admission hold
    // -----------------------------------------------------------------------------------------------------------------

    /**
     * @param  array{action: string, idempotency_key: string, evidence_type?: ?string, evidence_reference?: ?string, evidence_note?: ?string}  $input
     * @return array<string, mixed>
     */
    public function recoverRefund(User $actor, string $bookingGuid, string $refundGuid, array $input): array
    {
        [$actor, $ref] = $this->context($actor, $bookingGuid);
        $row = DB::table('booking_refund_requests')->where('guid', $refundGuid)->where('booking_guid', $bookingGuid)->first() ?? throw new ModelNotFoundException;
        $action = $input['action'];
        $this->assertRefundRole($actor, $action, $row);
        $hash = $this->hash($bookingGuid, $refundGuid, $input);
        if ($stored = $this->storedAction($actor->guid, $input['idempotency_key'], $hash)) {
            return $this->summary($bookingGuid, $stored, $refundGuid);
        }
        // A refund that may already be in flight is judged by a fresh provider answer; one that never left needs none.
        $lookup = in_array($action, ['reverify', 'route_manual', 'reject'], true) && ($action === 'reverify' || $row->dispatch_started_at !== null)
            ? $this->verify($row->payment_attempt_guid) : null;
        try {
            $result = $this->refunds->inRefundDomain($refundGuid, fn (object $booking, ?object $intent, ?object $attempt, object $request): array => $this->decideRefund(
                $actor, $ref, $input, $hash, $lookup, $booking, $intent, $attempt, $request));
        } catch (UniqueConstraintViolationException) {
            throw new BookingException('Idempotency key already used with different input.', 'idempotency_conflict');
        }
        if ($result === null) {
            throw new ModelNotFoundException;
        }
        if ($result['replay'] ?? false) {
            return $this->summary($bookingGuid, $result['audit_row'], $refundGuid);
        }
        if ($result['refusal']) {
            throw new BookingException('The evidence is not sufficient for this decision.', 'insufficient_evidence', ['reasons' => $result['refusal'], 'audit_guid' => $result['audit']]);
        }

        return $this->summary($bookingGuid, DB::table('payment_recovery_actions')->where('guid', $result['audit'])->first(), $refundGuid);
    }

    /** @return array<string, mixed> */
    private function decideRefund(User $actor, object $ref, array $input, string $hash, ?string $lookup, object $booking, ?object $intent, ?object $attempt, object $request): array
    {
        $actor = $this->scope->authorize($actor, $ref->destination_guid, true);
        $action = $input['action'];
        $this->assertRefundRole($actor, $action, $request);
        if ($stored = $this->storedAction($actor->guid, $input['idempotency_key'], $hash, true)) {
            return ['replay' => true, 'audit_row' => $stored];
        }
        $dispatched = $request->dispatch_started_at !== null;
        $facts = ['lookup' => $lookup, 'refund_status' => $request->status, 'review_reason' => $request->review_reason];
        $reasons = [];
        if ($action !== 'reverify' && $request->status !== 'needs_review') {
            $reasons[] = 'refund_not_in_review';
        }
        if ($action === 'resume_provider') {
            if ($request->refund_route !== 'provider_api' || $request->refund_key === null) {
                $reasons[] = 'not_a_provider_refund';
            }
            if (! in_array($request->review_reason, self::RESUMABLE_REFUND_REASONS, true)) {
                $reasons[] = 'review_reason_not_resumable:'.$request->review_reason;
            }
            if (! $this->refunds->paymentStillSettled($intent, $attempt) || ! $intent) {
                $reasons[] = 'payment_state_changed';
            }
            if ($this->refunds->usedAdmission($booking->guid)) {
                $reasons[] = 'ticket_already_used';
            }
        } elseif (in_array($action, ['route_manual', 'reject'], true)) {
            $reasons = [...$reasons, ...$this->refundNotMovedReasons($input, $dispatched, $intent, $attempt, $lookup)];
            if ($request->provider_refund_reference !== null) {
                $reasons[] = 'provider_refund_reference_known';
            }
            if ($action === 'route_manual' && $this->refunds->usedAdmission($booking->guid)) {
                $reasons[] = 'ticket_already_used';
            }
        }
        $reasons = array_values(array_unique($reasons));
        $from = $request->status;
        $to = null;
        if (! $reasons) {
            $to = $this->applyRefundAction($actor, $input, $request, $dispatched);
        }
        $audit = $this->audit($booking->guid, 'refund_request', $request->guid, $actor, $input, $reasons ? 'refused' : 'applied', $reasons ? implode(',', $reasons) : null,
            $facts + ($reasons ? ['reasons' => $reasons] : []), $from, $to, $reasons ? null : $input['idempotency_key'], $hash);

        return ['replay' => false, 'audit' => $audit, 'refusal' => $reasons ?: null];
    }

    /** @return list<string> */
    private function refundNotMovedReasons(array $input, bool $dispatched, ?object $intent, ?object $attempt, ?string $lookup): array
    {
        if (! $dispatched) {
            return [];
        }
        $reasons = [];
        if (! in_array($input['evidence_type'] ?? null, ['provider_dashboard_no_refund', 'provider_support_confirmation'], true)) {
            $reasons[] = 'evidence_type_not_accepted';
        }
        if (($input['evidence_reference'] ?? '') === '') {
            $reasons[] = 'evidence_reference_required';
        }
        // The provider may have received the refund: only a fresh verified answer that still shows one paid, unrefunded payment frees it.
        if ($lookup !== 'done:paid' || ! $intent || ! $attempt || $intent->status !== 'paid' || $attempt->status !== 'paid') {
            $reasons[] = 'provider_state_not_verified_unrefunded';
        } elseif ($attempt->last_reconciled_at === null || CarbonImmutable::parse($attempt->last_reconciled_at, 'UTC')->lt(now()->subSeconds(self::FRESH_SECONDS))) {
            $reasons[] = 'verification_not_fresh';
        }

        return $reasons;
    }

    private function applyRefundAction(User $actor, array $input, object $request, bool $dispatched): ?string
    {
        $note = 'operator_'.$input['action'];
        switch ($input['action']) {
            case 'resume_provider':
                // The SAME key goes back to the provider; the hold stays. A dispatched request restarts its bounded send budget, never its key.
                $this->refunds->move($request, 'refund_pending', $actor->guid, $note, ['review_reason' => null, 'escalated_at' => null, 'last_error_code' => null,
                    'admission_hold' => 1, 'dispatch_claimed_until' => null, 'dispatch_claim_token' => null, 'reconcile_count' => 0,
                    'dispatch_attempts' => $dispatched ? 1 : 0, 'next_reconcile_at' => now()]);
                $guid = $request->guid;
                DB::afterCommit(function () use ($guid): void {
                    try {
                        ProcessMidtransRefund::dispatch($guid);
                    } catch (Throwable) {
                        Log::warning('Resumed provider refund awaits scheduled dispatch.', ['refund_request_guid' => $guid]);
                    }
                });

                return 'refund_pending';
            case 'route_manual':
                $this->refunds->move($request, 'manual_action_required', $actor->guid, $note, ['refund_route' => 'manual', 'admission_hold' => null,
                    'next_reconcile_at' => null, 'dispatch_claimed_until' => null, 'dispatch_claim_token' => null]);

                return 'manual_action_required';
            case 'reject':
                $this->refunds->move($request, 'rejected', $actor->guid, $note, ['active' => null, 'admission_hold' => null, 'next_reconcile_at' => null,
                    'decided_by_user_guid' => $actor->guid, 'decided_at' => now(), 'decision_note' => trim((string) $input['evidence_note']),
                    'dispatch_claimed_until' => null, 'dispatch_claim_token' => null]);

                return 'rejected';
            default:
                return null;
        }
    }

    // -----------------------------------------------------------------------------------------------------------------
    // Read side: one booking and the scoped queue
    // -----------------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function show(User $actor, string $bookingGuid): array
    {
        [$actor] = $this->context($actor, $bookingGuid);
        $booking = DB::table('bookings')->where('guid', $bookingGuid)->first(['status', 'payment_expires_at', 'notification_grace_expires_at']);
        $intent = DB::table('payment_intents')->where('booking_guid', $bookingGuid)->first();
        $attempts = $intent ? DB::table('payment_attempts')->where('payment_intent_guid', $intent->guid)->orderByDesc('attempt_number')->limit(20)->get() : collect();
        $superadmin = (int) $actor->role === 2;
        $refunds = DB::table('booking_refund_requests')->where('booking_guid', $bookingGuid)->orderByDesc('requested_at')->get();
        $allowed = ['reverify', 'note'];
        if ($superadmin && $intent && $intent->provider === 'midtrans') {
            if ($intent->status === 'needs_review') {
                $allowed[] = 'confirm_paid';
            }
            if ($intent->status === 'needs_review' || $attempts->contains(fn (object $attempt): bool => in_array($attempt->status, ['initiating', 'unknown'], true))) {
                $allowed[] = 'close_unpaid';
            }
        }
        $iso = fn (?string $value): ?string => $value ? CarbonImmutable::parse($value, 'UTC')->toIso8601String() : null;

        return [
            'booking_guid' => $bookingGuid, 'booking_status' => $booking->status, 'payment_expires_at' => $iso($booking->payment_expires_at),
            'internal_grace_expires_at' => $iso($booking->notification_grace_expires_at),
            'intent' => $intent ? ['guid' => $intent->guid, 'provider' => $intent->provider, 'environment' => $intent->environment, 'status' => $intent->status,
                'review_reason' => $intent->review_reason, 'review_flagged_at' => $iso($intent->review_flagged_at)] : null,
            'attempts' => $attempts->map(fn (object $attempt): array => ['guid' => $attempt->guid, 'attempt_number' => (int) $attempt->attempt_number, 'method' => $attempt->method,
                'channel' => $attempt->channel, 'status' => $attempt->status, 'provider_order_known' => $attempt->provider_reference !== null,
                'last_error_code' => $attempt->last_error_code, 'escalated_at' => $iso($attempt->escalated_at), 'recovery_exhausted_at' => $iso($attempt->recovery_exhausted_at),
                'last_reconciled_at' => $iso($attempt->last_reconciled_at)])->all(),
            'allowed_actions' => $allowed,
            'refund_requests' => $refunds->map(fn (object $refund): array => ['guid' => $refund->guid, 'status' => $refund->status, 'review_reason' => $refund->review_reason,
                'refund_route' => $refund->refund_route, 'admission_hold' => $refund->admission_hold !== null, 'dispatched' => $refund->dispatch_started_at !== null,
                'escalated_at' => $iso($refund->escalated_at),
                'allowed_actions' => $refund->status === 'needs_review' ? array_values(array_filter(['reverify', $refund->refund_route === 'provider_api' ? 'resume_provider' : null,
                    ($refund->dispatch_started_at === null || $superadmin) ? 'route_manual' : null, ($refund->dispatch_started_at === null || $superadmin) ? 'reject' : null])) : ['reverify']])->all(),
            'audit' => DB::table('payment_recovery_actions')->where('booking_guid', $bookingGuid)->orderByDesc('created_at')->orderByDesc('guid')->limit(20)
                ->get(['guid', 'action', 'outcome', 'refusal_reason', 'actor_guid', 'subject_type', 'subject_guid', 'from_status', 'to_status', 'evidence_type', 'created_at'])
                ->map(fn (object $row): array => (array) $row)->all(),
        ];
    }

    /**
     * Cases that need a human across the actor's destinations: payments in review, open attempts whose bounded recovery ended or was
     * escalated, and refund requests in review. Newest first, bounded.
     *
     * @return array{cases: list<array<string, mixed>>}
     */
    public function queue(User $actor): array
    {
        $actor = $this->scope->actor($actor);
        $base = fn () => DB::table('bookings as b')->join('destination_quotas as q', 'q.guid', '=', 'b.destination_quota_guid');
        $payments = $this->scope->apply($base()->join('payment_intents as i', 'i.booking_guid', '=', 'b.guid'), $actor, 'q.destination_guid')
            ->where('i.status', 'needs_review')
            ->whereNotExists(fn ($settled) => $settled->selectRaw('1')->from('booking_refund_requests as r')->whereColumn('r.booking_guid', 'b.guid')->whereIn('r.status', BookingRefundService::SETTLED_STATUSES))
            ->orderByDesc('i.updated_at')->limit(50)->get(['b.guid as booking_guid', 'i.guid as subject_guid', 'i.review_reason as reason', 'i.updated_at as since'])
            ->map(fn (object $row): array => ['kind' => 'payment_review'] + (array) $row);
        $attempts = $this->scope->apply($base()->join('payment_intents as i', 'i.booking_guid', '=', 'b.guid')->join('payment_attempts as a', 'a.payment_intent_guid', '=', 'i.guid'), $actor, 'q.destination_guid')
            ->where('a.provider', 'midtrans')->whereIn('a.status', self::OPEN_ATTEMPT_STATUSES)->whereNotNull('a.escalated_at')
            ->orderByDesc('a.escalated_at')->limit(50)->get(['b.guid as booking_guid', 'a.guid as subject_guid', 'a.escalation_reason as reason', 'a.escalated_at as since'])
            ->map(fn (object $row): array => ['kind' => 'unresolved_attempt'] + (array) $row);
        $refunds = $this->scope->apply($base()->join('booking_refund_requests as r', 'r.booking_guid', '=', 'b.guid'), $actor, 'q.destination_guid')
            ->where('r.status', 'needs_review')->orderByDesc('r.updated_at')->limit(50)
            ->get(['b.guid as booking_guid', 'r.guid as subject_guid', 'r.review_reason as reason', 'r.updated_at as since'])
            ->map(fn (object $row): array => ['kind' => 'refund_review'] + (array) $row);

        return ['cases' => $payments->concat($attempts)->concat($refunds)->sortByDesc('since')->values()->take(100)->all()];
    }

    // -----------------------------------------------------------------------------------------------------------------
    // Shared plumbing
    // -----------------------------------------------------------------------------------------------------------------

    /** @return array{0: User, 1: object} the active staff actor and the booking's quota and destination (404 outside the actor's scope) */
    private function context(User $actor, string $bookingGuid): array
    {
        $actor = $this->scope->actor($actor);
        $ref = DB::table('bookings as b')->join('destination_quotas as q', 'q.guid', '=', 'b.destination_quota_guid')->where('b.guid', $bookingGuid)
            ->first(['b.guid', 'b.destination_quota_guid', 'q.destination_guid']);
        abort_unless($ref, 404);
        $this->scope->authorize($actor, $ref->destination_guid);

        return [$actor, $ref];
    }

    /** One claimed authenticated lookup; "<result>:<normalized provider answer>" so only a clean answer counts as evidence. */
    private function verify(string $attemptGuid): string
    {
        $payments = app(MidtransPaymentService::class);
        $result = $payments->reverify($attemptGuid);

        return $result.':'.($payments->lastOutcome() ?? '-');
    }

    private function assertRole(User $actor, string $action): void
    {
        if (in_array($action, self::SUPERADMIN_ACTIONS, true) && (int) $actor->role !== 2) {
            throw new AuthorizationException;
        }
    }

    /** A refund that may already be in flight is a money decision too. */
    private function assertRefundRole(User $actor, string $action, object $request): void
    {
        if (in_array($action, ['route_manual', 'reject'], true) && $request->dispatch_started_at !== null && (int) $actor->role !== 2) {
            throw new AuthorizationException;
        }
    }

    /** @param array<string, mixed> $input */
    private function hash(string $bookingGuid, ?string $subject, array $input): string
    {
        return hash('sha256', json_encode([$bookingGuid, $subject, $input['action'], $input['evidence_type'] ?? null, $input['evidence_reference'] ?? null,
            trim((string) ($input['evidence_note'] ?? ''))], JSON_THROW_ON_ERROR));
    }

    private function storedAction(string $actorGuid, string $key, string $hash, bool $lock = false): ?object
    {
        $row = DB::table('payment_recovery_actions')->where('actor_guid', $actorGuid)->where('idempotency_key', $key)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if ($row && ! hash_equals((string) $row->request_hash, $hash)) {
            throw new BookingException('Idempotency key already used with different input.', 'idempotency_conflict');
        }

        return $row;
    }

    /** @param array<string, mixed> $input */
    private function audit(string $bookingGuid, string $subjectType, string $subjectGuid, User $actor, array $input, string $outcome, ?string $refusal, array $facts,
        ?string $from, ?string $to, ?string $key, string $hash): string
    {
        $guid = (string) Str::uuid();
        DB::table('payment_recovery_actions')->insert(['guid' => $guid, 'booking_guid' => $bookingGuid, 'subject_type' => $subjectType, 'subject_guid' => $subjectGuid,
            'actor_guid' => $actor->guid, 'action' => $input['action'], 'outcome' => $outcome, 'refusal_reason' => $refusal === null ? null : substr($refusal, 0, 64),
            'evidence_type' => $input['evidence_type'] ?? null, 'evidence_reference' => $input['evidence_reference'] ?? null,
            'evidence_note' => isset($input['evidence_note']) ? trim((string) $input['evidence_note']) : null, 'verified_facts' => json_encode($facts, JSON_THROW_ON_ERROR),
            'from_status' => $from, 'to_status' => $to, 'idempotency_key' => $key, 'request_hash' => $hash, 'created_at' => now()]);
        Log::info('Operator payment recovery recorded.', ['audit_guid' => $guid, 'booking_guid' => $bookingGuid, 'action' => $input['action'], 'outcome' => $outcome, 'actor_guid' => $actor->guid]);

        return $guid;
    }

    /** @return array<string, mixed> live state after (or instead of) an action */
    private function summary(string $bookingGuid, ?object $audit, ?string $refundGuid = null): array
    {
        $intent = DB::table('payment_intents')->where('booking_guid', $bookingGuid)->first(['guid', 'status', 'review_reason']);
        $attempts = $intent ? DB::table('payment_attempts')->where('payment_intent_guid', $intent->guid)->orderByDesc('attempt_number')->limit(20)
            ->get(['guid', 'status', 'last_error_code'])->map(fn (object $row): array => (array) $row)->all() : [];
        $summary = ['booking_guid' => $bookingGuid, 'action' => $audit?->action, 'outcome' => $audit?->outcome, 'audit_guid' => $audit?->guid,
            'booking_status' => DB::table('bookings')->where('guid', $bookingGuid)->value('status'), 'intent_status' => $intent?->status, 'attempts' => $attempts];
        if ($refundGuid !== null) {
            $refund = DB::table('booking_refund_requests')->where('guid', $refundGuid)->first(['guid', 'status', 'review_reason', 'refund_route', 'admission_hold']);
            $summary['refund'] = ['guid' => $refund->guid, 'status' => $refund->status, 'review_reason' => $refund->review_reason, 'refund_route' => $refund->refund_route,
                'admission_hold' => $refund->admission_hold !== null];
        }

        return $summary;
    }
}
