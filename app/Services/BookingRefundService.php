<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Full-refund workflow (request -> scoped admin decision -> manual action -> verified provider adjustment).
 *
 * Approval never moves money and never writes `refunded`: that status is set only by recordVerifiedAdjustment(), which the
 * payment engine calls after it has applied a verified provider refund/reversal event. No provider refund transport exists
 * until DOKU confirms the entitlement and contract, so every approved request stays `manual_action_required`.
 * Lock order matches payments and check-in: destination -> quota -> booking -> tickets.
 */
class BookingRefundService
{
    /** Statuses that keep the single per-booking slot occupied. */
    public const ACTIVE_STATUSES = ['requested', 'approved', 'manual_action_required', 'refund_pending', 'needs_review'];

    /** Terminal statuses meaning the customer's money was returned, by either channel. */
    public const SETTLED_STATUSES = ['refunded', 'manual_refunded'];

    /** Accepted manual settlement methods; the reference must identify the transfer/disbursement in that channel. */
    public const SETTLEMENT_METHODS = ['bank_transfer', 'ewallet_transfer', 'cash_disbursement'];

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
            $intent = DB::table('payment_intents')->where('booking_guid', $guid)->where('provider', 'doku')->lockForUpdate()->first();
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
            $request = DB::table('booking_refund_requests')->where('guid', $refundGuid)->where('booking_guid', $bookingGuid)->lockForUpdate()->first()
                ?? throw new ModelNotFoundException;
            if ($request->status !== 'requested') {
                throw new BookingException('This refund request was already decided.', 'refund_not_pending', ['status' => $request->status]);
            }
            $note = isset($input['note']) ? trim((string) $input['note']) : null;
            if ($input['decision'] === 'reject') {
                $this->move($request, 'rejected', $actor->guid, $note, ['active' => null, 'decided_by_user_guid' => $actor->guid, 'decided_at' => now(), 'decision_note' => $note]);
            } elseif ($this->usedAdmission($bookingGuid) || $this->payments->entitlement($booking) !== 'paid' || $booking->status !== 'confirmed'
                || DB::table('booking_refund_requests')->where('booking_guid', $bookingGuid)->whereIn('status', self::SETTLED_STATUSES)->exists()) {
                $this->move($request, 'needs_review', $actor->guid, $note, ['review_reason' => 'usage_or_payment_changed', 'decided_by_user_guid' => $actor->guid, 'decided_at' => now()]);
            } else {
                $this->move($request, 'approved', $actor->guid, $note, ['decided_by_user_guid' => $actor->guid, 'decided_at' => now(), 'decision_note' => $note]);
                // No verified provider refund capability: money movement is a human/provider action outside this system.
                $this->move(DB::table('booking_refund_requests')->where('guid', $refundGuid)->first(), 'manual_action_required', null, 'provider_refund_unavailable', []);
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
        $this->move($request, 'refunded', null, 'verified_provider_adjustment', ['active' => null, 'refunded_at' => now(), 'provider_correlation' => $eventGuid]);
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
