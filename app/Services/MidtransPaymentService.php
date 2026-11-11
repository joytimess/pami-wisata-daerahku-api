<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Jobs\ProcessMidtransPaymentEvent;
use App\Models\User;
use App\Services\Midtrans\MidtransChannelRegistry;
use App\Services\Midtrans\MidtransClient;
use App\Support\BookingRules;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Creates Midtrans Core API payment attempts and verifies them afterwards.
 *
 * The attempt is reserved and committed BEFORE the network call, so a crash or timeout after the provider accepted the Charge
 * leaves a durable `initiating`/`unknown` attempt on a known order id. A retry with the same key (or any later request) returns
 * that attempt; a second order is never created for it.
 *
 * Money is only ever confirmed from an authenticated Status lookup of a stored order. A notification is a durable trigger, never
 * evidence; the webhook, the scheduler and the owner refresh all go through the same claimed lookup.
 */
class MidtransPaymentService
{
    /** Attempt statuses that can still change at the provider. */
    private const OPEN_ATTEMPT_STATUSES = ['initiating', 'pending', 'unknown'];

    private const OPERATION = 'midtrans_payment.initiate';

    /** Claim lease of one provider lookup: longer than the HTTP timeout, short enough that a crashed worker is retried soon. */
    private const LEASE_SECONDS = 90;

    private const RECEIPT_LEASE_SECONDS = 120;

    private const RECEIPT_MAX_ATTEMPTS = 10;

    /** Operator re-verification collapses into one provider call per attempt within this many seconds. */
    private const OPERATOR_MIN_INTERVAL_SECONDS = 10;

    private const REFRESH_MIN_INTERVAL_SECONDS = 10;

    private const NOTIFICATION_MIN_INTERVAL_SECONDS = 5;

    /**
     * Provider evidence that is neither payable nor safely retryable: the payment is held for a human instead of being retried
     * or guessed. (`merchant_mismatch` here is the PROVIDER's merchant differing from the bound one.)
     */
    public const REVIEW_REASONS = ['partial_adjustment_review', 'fraud_status_review', 'merchant_mismatch', 'transaction_mismatch'];

    /** Evidence that does not match the stored attempt. Retried a bounded number of consecutive rounds, then reviewed. */
    private const INVALID_REASONS = ['provider_response_invalid', 'provider_expiry_invalid'];

    private const INVALID_ESCALATION_ROUNDS = 5;

    /**
     * How long after its instruction window an unresolved attempt is still polled on a schedule. The attempt then keeps its quota
     * and stays open, scheduled lookups stop, and only a notification, an owner refresh or an operator re-verification can move it:
     * a Charge outcome that stays unknown is never retried with a new order and never released on a timeout.
     */
    private const RECOVERY_WINDOW_SECONDS = 6 * 3600;

    private ?string $lastOutcome = null;

    public function __construct(private MidtransClient $client, private MidtransChannelRegistry $registry, private TicketService $tickets) {}

    /**
     * @param  array{idempotency_key: string, method_code: string, channel: string}  $input
     * @return array<string, mixed>
     */
    public function initiate(User $owner, string $guid, array $input): array
    {
        app(PaymentService::class)->ownerBooking($owner, $guid);
        $quota = DB::table('bookings')->where('guid', $guid)->value('destination_quota_guid');
        $reservation = DB::transaction(function () use ($owner, $guid, $input, $quota): array {
            $booking = $this->lockedBooking($guid, $quota);
            $actor = User::whereKey($owner->guid)->sharedLock()->first();
            abort_unless($actor && $actor->status === 'active' && (int) $actor->role === 0 && $booking->user_guid === $actor->guid, 404);
            $hash = hash('sha256', json_encode(['booking_guid' => $guid, 'method_code' => $input['method_code'], 'channel' => $input['channel']], JSON_THROW_ON_ERROR));
            DB::table('idempotency_records')->insertOrIgnore([
                'guid' => (string) Str::uuid(), 'user_guid' => $actor->guid, 'operation' => self::OPERATION,
                'key' => $input['idempotency_key'], 'request_hash' => $hash, 'status' => 'processing',
                'expires_at' => now()->addDays(BookingRules::IDEMPOTENCY_DAYS), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $record = DB::table('idempotency_records')->where('user_guid', $actor->guid)->where('operation', self::OPERATION)
                ->where('key', $input['idempotency_key'])->lockForUpdate()->first();
            if (! hash_equals($record->request_hash, $hash)) {
                throw new BookingException('Idempotency key already used with different input.', 'idempotency_conflict');
            }
            if (now()->gte($record->expires_at)) {
                throw new BookingException('Check the current payment before retrying.', 'idempotency_key_expired');
            }
            if ($booking->status === 'awaiting_payment') {
                app(BookingDocumentService::class)->ensureInvoice($booking);
            }
            if ($record->status === 'completed') {
                // Replays are rebuilt from live state so an expired instruction is never shown again.
                $replayAttempt = $record->response_reference ? DB::table('payment_attempts')->where('guid', $record->response_reference)->first() : null;
                $replayIntent = $replayAttempt ? DB::table('payment_intents')->where('guid', $replayAttempt->payment_intent_guid)->first() : null;
                if ($replayAttempt && $replayIntent) {
                    return ['response' => $this->response($booking, $replayIntent, $replayAttempt)];
                }
                $stored = json_decode($record->response_body, true, 512, JSON_THROW_ON_ERROR);

                return ['response' => json_decode(Crypt::decryptString($stored['ciphertext']), true, 512, JSON_THROW_ON_ERROR)];
            }
            $intent = DB::table('payment_intents')->where('booking_guid', $guid)->lockForUpdate()->first();
            if ($intent) {
                $this->assertSnapshot($booking, $intent);
                $attempt = DB::table('payment_attempts')->where('payment_intent_guid', $intent->guid)
                    ->whereIn('status', self::OPEN_ATTEMPT_STATUSES)->orderByDesc('attempt_number')->lockForUpdate()->first()
                    ?? DB::table('payment_attempts')->where('payment_intent_guid', $intent->guid)->orderByDesc('attempt_number')->lockForUpdate()->first();
                if ($intent->status === 'needs_review') {
                    throw new BookingException('Payment requires investigation.', 'payment_needs_review');
                }
                if ($attempt && in_array($attempt->status, ['initiating', 'pending', 'unknown', 'paid', 'reversed'], true)) {
                    DB::table('idempotency_records')->where('guid', $record->guid)->update(['response_reference' => $attempt->guid]);

                    return ['response' => $this->response($booking, $intent, $attempt)];
                }
            }
            $this->assertPayable($booking);
            $startedAt = CarbonImmutable::now()->startOfSecond();
            $expiresAt = app(PaymentService::class)->instructionExpiresAt($booking, $startedAt);
            $definition = $this->registry->find($input['method_code'], $input['channel']);
            if ($reason = $definition ? $this->registry->reason($definition['code']) : 'channel_unavailable') {
                throw new BookingException('Payment channel is unavailable.', $reason);
            }
            if ($reason = $this->client->expiryWindowReason($expiresAt)) {
                throw new BookingException('Payment instruction window is unavailable.', $reason);
            }
            if (! $intent) {
                $intent = (object) [
                    'guid' => (string) Str::uuid(), 'booking_guid' => $guid, 'provider' => 'midtrans', 'environment' => $this->client->environment(),
                    'amount' => (int) $booking->total_amount, 'currency' => $booking->currency, 'status' => 'requires_payment',
                    'merchant_reference' => (string) Str::uuid(), 'expires_at' => $booking->payment_expires_at,
                    'created_at' => now(), 'updated_at' => now(),
                ];
                DB::table('payment_intents')->insert((array) $intent);
            }
            if (! in_array($intent->status, ['requires_payment', 'processing'], true)) {
                throw new BookingException('Check the final payment status.', 'payment_needs_review');
            }
            $number = DB::table('payment_attempts')->where('payment_intent_guid', $intent->guid)->max('attempt_number') ?? 0;
            $attemptGuid = (string) Str::uuid();
            $attempt = (object) [
                'guid' => $attemptGuid, 'payment_intent_guid' => $intent->guid, 'provider' => 'midtrans', 'environment' => $intent->environment,
                'attempt_number' => $number + 1, 'method' => $input['method_code'], 'channel' => $input['channel'],
                // The Midtrans order_id is derived from the attempt so the booking, intent and order stay bound and cannot collide on retries.
                'merchant_reference' => 'PAMI-'.$attemptGuid, 'provider_request_id' => (string) Str::uuid(),
                // Immutable merchant binding: later lookups verify against this, not against whatever is configured at that time.
                'merchant_id' => trim((string) config('midtrans.merchant_id')) ?: null,
                'request_hash' => $hash, 'amount' => $intent->amount, 'currency' => $intent->currency,
                'status' => 'initiating', 'presentation_type' => 'unavailable', 'instruction_expires_at' => $expiresAt,
                'started_at' => $startedAt, 'next_reconcile_at' => now()->addMinutes(2), 'created_at' => now(), 'updated_at' => now(),
            ];
            DB::table('payment_attempts')->insert((array) $attempt);
            DB::table('payment_intents')->where('guid', $intent->guid)->update(['status' => 'processing', 'updated_at' => now()]);
            DB::table('idempotency_records')->where('guid', $record->guid)->update(['response_reference' => $attempt->guid, 'updated_at' => now()]);
            $booking->payment_approved_at = $this->approvedAt($booking);

            return ['attempt' => $attempt, 'booking' => $booking];
        }, 3);
        if (isset($reservation['response'])) {
            return $reservation['response'];
        }
        // The durable reservation is committed before the provider can accept a payment. No automatic retry from here on.
        $started = hrtime(true);
        try {
            $result = $this->client->charge($reservation['attempt'], $reservation['booking']);
        } catch (Throwable $exception) {
            // The client classifies provider failures itself, so anything reaching here is an internal fault of unknown phase.
            Log::warning('Midtrans Charge raised an internal fault.', ['attempt_guid' => $reservation['attempt']->guid, 'exception_class' => $exception::class]);
            $result = ['status' => 'unknown', 'reason' => 'provider_unavailable'];
        }
        Log::info('Midtrans initiation resolved.', ['attempt_guid' => $reservation['attempt']->guid, 'provider' => 'midtrans',
            'environment' => $reservation['attempt']->environment, 'channel' => $reservation['attempt']->channel,
            'status' => $result['status'], 'latency_ms' => (int) ((hrtime(true) - $started) / 1000000)]);

        return $this->saveInitiation($reservation['attempt'], $reservation['booking'], $result);
    }

    /** @param array<string, mixed> $result */
    private function saveInitiation(object $attempt, object $booking, array $result): array
    {
        $bookingGuid = $booking->guid;
        $response = DB::transaction(function () use ($attempt, $booking, $result): array {
            $booking = $this->lockedBooking($booking->guid, $booking->destination_quota_guid);
            $intent = DB::table('payment_intents')->where('guid', $attempt->payment_intent_guid)->lockForUpdate()->first();
            $attempt = DB::table('payment_attempts')->where('guid', $attempt->guid)->lockForUpdate()->first();
            // A transaction id another attempt already holds can never be bound (unique index): quarantine, do not fail.
            $conflict = ! empty($result['provider_reference']) && $attempt->provider_reference !== $result['provider_reference']
                && $this->referenceTaken($attempt, (string) $result['provider_reference']);
            if ($conflict) {
                $result = ['status' => 'unknown', 'reason' => 'provider_reference_conflict'];
            }
            try {
                $expiry = isset($result['expires_at']) ? CarbonImmutable::parse($result['expires_at'])->utc() : null;
            } catch (\Exception) {
                $expiry = null;
            }
            $presentation = $result['presentation_type'] ?? 'virtual_account';
            $instruction = $result['instruction'] ?? [];
            $complete = ! empty($result['provider_reference']) && match ($presentation) {
                'virtual_account' => ! empty($instruction['va_number']),
                'qr_image' => ! empty($instruction['_provider']['image_url']),
                default => false,
            };
            // A lookup may have moved the attempt on before the Charge response arrived: keep that outcome and only add the
            // instruction it could not recover. A final outcome is never replaced by a pending one.
            $recoverInstructions = in_array($attempt->status, ['pending', 'unknown'], true) && $attempt->instruction_ciphertext === null
                && ($result['status'] ?? null) === 'pending' && $intent->status !== 'needs_review' && $attempt->last_error_code !== 'provider_reference_conflict'
                && ($attempt->provider_reference === null || ! isset($result['provider_reference']) || $attempt->provider_reference === $result['provider_reference']);
            if ($attempt->status === 'initiating' || $recoverInstructions) {
                $status = in_array($result['status'] ?? null, ['pending', 'failed'], true) ? $result['status'] : 'unknown';
                if ($status === 'pending' && (! $expiry || $expiry->gt($attempt->instruction_expires_at) || $expiry->lte(now()) || ! $complete)) {
                    $status = 'unknown';
                }
                $changes = [
                    'status' => $status, 'provider_reference' => $result['provider_reference'] ?? $attempt->provider_reference,
                    'instruction_ciphertext' => $status === 'pending' ? Crypt::encryptString(json_encode($instruction, JSON_THROW_ON_ERROR)) : null,
                    'presentation_type' => $status === 'pending' ? $presentation : 'unavailable',
                    'last_error_code' => $status === 'unknown' ? ($result['reason'] ?? 'provider_response_invalid') : ($status === 'failed' ? ($result['reason'] ?? 'provider_create_failed') : null),
                    'finished_at' => $status === 'failed' ? now() : null,
                    'next_reconcile_at' => $status === 'failed' ? null : $attempt->next_reconcile_at, 'updated_at' => now(),
                ];
                try {
                    DB::table('payment_attempts')->where('guid', $attempt->guid)->update($changes);
                } catch (UniqueConstraintViolationException) {
                    // Lost a race for the same transaction id between the check above and the write.
                    $conflict = true;
                    $status = 'unknown';
                    DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['status' => 'unknown', 'provider_reference' => $attempt->provider_reference,
                        'instruction_ciphertext' => null, 'presentation_type' => 'unavailable', 'last_error_code' => 'provider_reference_conflict',
                        'finished_at' => null, 'next_reconcile_at' => $attempt->next_reconcile_at, 'updated_at' => now()]);
                }
                if ($status === 'failed') {
                    // Only a payment that is still processing: an intent in review is left for an operator.
                    DB::table('payment_intents')->where('guid', $intent->guid)->where('status', 'processing')->update(['status' => 'requires_payment', 'updated_at' => now()]);
                }
                if ($conflict) {
                    Log::error('Midtrans returned a transaction id that belongs to another attempt; payment quarantined.', ['attempt_guid' => $attempt->guid]);
                    app(PaymentSettlementService::class)->flagAmbiguousProviderOutcome($attempt->guid, 'provider_reference_conflict');
                }
            }
            $response = $this->response($booking, DB::table('payment_intents')->where('guid', $intent->guid)->first(), DB::table('payment_attempts')->where('guid', $attempt->guid)->first());
            DB::table('idempotency_records')->where('operation', self::OPERATION)->where('response_reference', $attempt->guid)
                ->where('status', 'processing')->update(['status' => 'completed', 'response_body' => json_encode([
                    'ciphertext' => Crypt::encryptString(json_encode($response, JSON_THROW_ON_ERROR)),
                ], JSON_THROW_ON_ERROR), 'updated_at' => now()]);

            return $response;
        }, 3);
        // A Charge rejected after the window ended may leave nothing payable: close the booking and release its quota.
        app(PaymentSettlementService::class)->closeAfterOutcome($bookingGuid);

        return $response;
    }

    // -----------------------------------------------------------------------------------------------------------------
    // Notifications, authenticated Status lookups, reconciliation and recovery
    // -----------------------------------------------------------------------------------------------------------------

    /**
     * Stores a signature-verified notification as a durable receipt and queues its processing. The receipt is a TRIGGER only:
     * nothing in it is financial evidence. An order that is not a local Midtrans attempt of this environment is recorded for audit
     * and never causes a provider request.
     *
     * Only fields covered by the signature, or drawn from a closed vocabulary, identify a delivery, so unsigned noise cannot
     * multiply receipts or provider lookups.
     *
     * @param  array<string, mixed>  $payload  already validated and signature-checked by the controller
     */
    public function receiveNotification(array $payload, string $rawBody): string
    {
        $environment = $this->client->environment();
        $attempt = DB::table('payment_attempts')->where('provider', 'midtrans')->where('environment', $environment)
            ->where('merchant_reference', $payload['order_id'])->first(['guid', 'payment_intent_guid']);
        $key = 'notification:'.hash('sha256', implode('|', [$payload['order_id'], $payload['status_code'], $payload['gross_amount'],
            $payload['transaction_status'], $payload['fraud_status'] ?? '']));
        DB::table('payment_events')->insertOrIgnore([
            'guid' => (string) Str::uuid(), 'payment_intent_guid' => $attempt?->payment_intent_guid, 'payment_attempt_guid' => $attempt?->guid,
            'provider' => 'midtrans', 'environment' => $environment, 'provider_event_key' => $key, 'payload_hash' => hash('sha256', $rawBody),
            'verification' => 'unverified', 'event_type' => 'status', 'disposition' => $attempt ? 'received' : 'unknown_order',
            'received_at' => now(), 'metadata' => json_encode(['source' => 'midtrans_notification', 'payload_ciphertext' => Crypt::encryptString($rawBody)], JSON_THROW_ON_ERROR),
        ]);
        $event = DB::table('payment_events')->where('provider', 'midtrans')->where('environment', $environment)->where('provider_event_key', $key)->first();
        Log::info('Midtrans notification received.', ['event_guid' => $event->guid, 'known_order' => $attempt !== null, 'environment' => $environment]);
        if ($event->disposition === 'received' && $event->payment_attempt_guid !== null) {
            try {
                ProcessMidtransPaymentEvent::dispatch($event->guid);
            } catch (Throwable) {
                // The receipt is durable; the scheduler replays it.
                Log::warning('Midtrans notification awaits scheduled replay.', ['event_guid' => $event->guid]);
            }
        }

        return $event->guid;
    }

    /**
     * Worker entry for one stored event: a notification receipt (look the known order up, then mark the receipt processed) or a
     * verified evidence event that was recorded but not yet applied. Safe to run any number of times in parallel.
     */
    public function processEvent(string $eventGuid): void
    {
        $event = DB::table('payment_events')->where('guid', $eventGuid)->where('provider', 'midtrans')->first();
        if (! $event) {
            return;
        }
        if ($event->verification === 'verified') {
            if ($event->disposition === 'recorded') {
                $this->applyEvidence($event->guid);
            }

            return;
        }
        if ($event->verification !== 'unverified' || $event->disposition !== 'received' || $event->payment_attempt_guid === null) {
            return;
        }
        // Durable claim: parallel workers cannot process one receipt twice, and a crashed worker's lease simply expires.
        $claimed = DB::table('payment_events')->where('guid', $event->guid)->where('disposition', 'received')
            ->where(fn ($query) => $query->whereNull('claimed_until')->orWhere('claimed_until', '<=', now()))
            ->update(['claimed_until' => now()->addSeconds(self::RECEIPT_LEASE_SECONDS), 'process_attempts' => DB::raw('process_attempts + 1')]);
        if ($claimed !== 1) {
            return;
        }
        try {
            $result = $this->lookup($event->payment_attempt_guid, 'notification');
        } catch (Throwable $exception) {
            Log::warning('Midtrans notification processing failed.', ['event_guid' => $event->guid, 'exception_class' => $exception::class]);
            $result = 'failed';
        }
        $attempts = (int) DB::table('payment_events')->where('guid', $event->guid)->value('process_attempts');
        if (in_array($result, ['done', 'skipped'], true)) {
            DB::table('payment_events')->where('guid', $event->guid)->update(['disposition' => 'processed', 'claimed_until' => null]);
        } elseif ($result === 'throttled') {
            // Another lookup of this order just ran: look again shortly, and do not count it as a failure.
            DB::table('payment_events')->where('guid', $event->guid)->update(['process_attempts' => DB::raw('process_attempts - 1'),
                'claimed_until' => now()->addSeconds(self::NOTIFICATION_MIN_INTERVAL_SECONDS + 1)]);
        } elseif ($attempts >= self::RECEIPT_MAX_ATTEMPTS) {
            // Attempt-level reconciliation keeps covering the order; the receipt itself is parked visibly.
            DB::table('payment_events')->where('guid', $event->guid)->update(['disposition' => 'unprocessable', 'claimed_until' => null]);
            Log::error('Midtrans notification receipt could not be processed.', ['event_guid' => $event->guid, 'attempt_guid' => $event->payment_attempt_guid]);
        } else {
            DB::table('payment_events')->where('guid', $event->guid)->update(['claimed_until' => now()->addSeconds(min(600, 30 * 2 ** min($attempts, 5)) + random_int(0, 15))]);
        }
    }

    /**
     * Authenticated Status lookup of a PAID attempt whose refund is unresolved. The provider's answer is recorded and applied through
     * the shared settlement path, so a verified full refund (and only that) reverses the attempt and settles the refund request.
     * Returns done|throttled|skipped|failed, exactly like the other claimed lookups.
     */
    public function verifyRefund(string $attemptGuid): string
    {
        try {
            return $this->lookup($attemptGuid, 'refund');
        } catch (Throwable $exception) {
            Log::warning('Midtrans refund verification failed.', ['attempt_guid' => $attemptGuid, 'exception_class' => $exception::class]);

            return 'failed';
        }
    }

    /**
     * Operator-requested authenticated Status lookup of one attempt of any non-reversed status. The answer is applied exactly like a
     * scheduled one, through the claimed lookup, so it can confirm, expire or quarantine but never invents an outcome.
     * Returns done|throttled|skipped|failed.
     */
    public function reverify(string $attemptGuid): string
    {
        $this->lastOutcome = null;
        try {
            return $this->lookup($attemptGuid, 'operator');
        } catch (Throwable $exception) {
            Log::warning('Midtrans operator re-verification failed.', ['attempt_guid' => $attemptGuid, 'exception_class' => $exception::class]);

            return 'failed';
        }
    }

    /** Scheduler/worker entry: one claimed Status lookup of a due open attempt. */
    public function reconcile(string $attemptGuid): void
    {
        try {
            $this->lookup($attemptGuid, 'scheduled');
        } catch (Throwable $exception) {
            Log::warning('Midtrans reconciliation failed.', ['attempt_guid' => $attemptGuid, 'exception_class' => $exception::class]);
        }
    }

    /**
     * Owner-triggered refresh: an authenticated Status lookup of the owner's own attempt, then the verified outcome is applied.
     * It never creates a Charge, never touches another provider, and shares the lookup claim with the workers, so rapid or
     * parallel calls collapse into one provider request.
     */
    public function refresh(User $owner, string $guid, string $attemptGuid): void
    {
        app(PaymentService::class)->ownerBooking($owner, $guid);
        $attempt = DB::table('payment_attempts as a')->join('payment_intents as i', 'i.guid', '=', 'a.payment_intent_guid')
            ->where('i.booking_guid', $guid)->where('a.guid', $attemptGuid)->where('a.provider', 'midtrans')->first(['a.guid', 'a.status']);
        abort_unless($attempt, 404);
        if (! in_array($attempt->status, self::OPEN_ATTEMPT_STATUSES, true)) {
            return;
        }
        $this->lookup($attempt->guid, 'refresh');
        foreach (DB::table('payment_events')->where('payment_attempt_guid', $attempt->guid)->where('verification', 'verified')->where('disposition', 'recorded')->pluck('guid') as $eventGuid) {
            $this->applyEvidence($eventGuid);
        }
    }

    /**
     * One claimed lookup. Returns done (an outcome was applied), skipped (not eligible), throttled (a recent lookup already ran)
     * or failed (no usable evidence; retry later).
     *
     * @param  'scheduled'|'refresh'|'notification'|'refund'|'operator'  $mode
     */
    private function lookup(string $attemptGuid, string $mode): string
    {
        if ($this->registry->verificationReason() !== null) {
            return $mode === 'notification' ? 'failed' : 'skipped';
        }
        $interval = match ($mode) {
            'scheduled' => 0, 'operator' => self::OPERATOR_MIN_INTERVAL_SECONDS, 'refresh' => self::REFRESH_MIN_INTERVAL_SECONDS, default => self::NOTIFICATION_MIN_INTERVAL_SECONDS,
        };
        $eligible = fn () => DB::table('payment_attempts')->where('guid', $attemptGuid)->where('provider', 'midtrans')
            ->where('environment', $this->client->environment())
            ->when(in_array($mode, ['notification', 'operator'], true), fn ($query) => $query->where('status', '!=', 'reversed'),
                fn ($query) => $mode === 'refund' ? $query->where('status', 'paid') : $query->whereIn('status', self::OPEN_ATTEMPT_STATUSES));
        $query = $eligible();
        if ($mode === 'scheduled') {
            $query->where('next_reconcile_at', '<=', now());
        }
        if ($interval > 0) {
            $query->where(fn ($window) => $window->whereNull('last_reconciled_at')->orWhere('last_reconciled_at', '<=', now()->subSeconds($interval)));
        }
        $lease = now()->addSeconds(self::LEASE_SECONDS)->toDateTimeString();
        $claimed = $query->update(['last_reconciled_at' => now(), 'reconcile_count' => DB::raw('reconcile_count + 1'),
            'next_reconcile_at' => DB::raw("CASE WHEN status IN ('initiating','pending','unknown') THEN '".$lease."' ELSE next_reconcile_at END")]);
        if ($claimed !== 1) {
            return $mode !== 'scheduled' && $eligible()->exists() ? 'throttled' : 'skipped';
        }
        $attempt = DB::table('payment_attempts')->where('guid', $attemptGuid)->first();
        try {
            $result = $this->record($attempt, $this->client->status($attempt), $mode);
        } catch (Throwable $exception) {
            Log::warning('Midtrans lookup could not be applied.', ['attempt_guid' => $attemptGuid, 'mode' => $mode, 'exception_class' => $exception::class]);
            DB::table('payment_attempts')->where('guid', $attemptGuid)->whereIn('status', self::OPEN_ATTEMPT_STATUSES)->update(['last_error_code' => 'reconciliation_unavailable']);
            $result = 'failed';
        }
        $this->scheduleNextLookup($attemptGuid);

        return $result;
    }

    /** Backs off an open attempt, or ends its scheduled recovery once the bounded window after its instruction expiry has passed. */
    private function scheduleNextLookup(string $attemptGuid): void
    {
        $row = DB::table('payment_attempts')->where('guid', $attemptGuid)->whereIn('status', self::OPEN_ATTEMPT_STATUSES)
            ->first(['reconcile_count', 'instruction_expires_at', 'recovery_exhausted_at', 'escalated_at', 'last_error_code', 'payment_intent_guid', 'status']);
        if (! $row) {
            return;
        }
        $overdue = $row->instruction_expires_at !== null && CarbonImmutable::parse($row->instruction_expires_at, 'UTC')->lte(now());
        $exhausted = $overdue && CarbonImmutable::parse($row->instruction_expires_at, 'UTC')->addSeconds(self::RECOVERY_WINDOW_SECONDS)->lte(now());
        if ($exhausted) {
            $newly = $row->recovery_exhausted_at === null;
            DB::table('payment_attempts')->where('guid', $attemptGuid)->whereIn('status', self::OPEN_ATTEMPT_STATUSES)->update([
                'next_reconcile_at' => null, 'recovery_exhausted_at' => $row->recovery_exhausted_at ?? now(), 'escalated_at' => $row->escalated_at ?? now(),
                'escalation_reason' => DB::raw($row->escalated_at === null ? "'recovery_exhausted'" : 'escalation_reason'), 'updated_at' => now()]);
            if ($newly) {
                Log::error('Midtrans payment attempt recovery window ended without proof; the attempt stays open and holds its quota for an operator.', [
                    'attempt_guid' => $attemptGuid, 'payment_intent_guid' => $row->payment_intent_guid, 'status' => $row->status, 'last_error_code' => $row->last_error_code]);
            }

            return;
        }
        // Past its window the schedule widens: the provider's own expiry has already decided the order, so frequent polling adds nothing.
        $cap = $overdue ? 900 : 300;
        DB::table('payment_attempts')->where('guid', $attemptGuid)->whereIn('status', self::OPEN_ATTEMPT_STATUSES)->whereNull('recovery_exhausted_at')
            ->update(['next_reconcile_at' => now()->addSeconds(min($cap, 30 * 2 ** min((int) $row->reconcile_count, 4)) + random_int(0, 15))]);
    }

    /** The normalized provider answer of the lookup this process ran last (`paid`, `refunded`, `pending`, `unknown:<reason>`, ...). */
    public function lastOutcome(): ?string
    {
        return $this->lastOutcome;
    }

    /** @param array<string, mixed> $outcome */
    private function record(object $attempt, array $outcome, string $mode = 'scheduled'): string
    {
        $this->lastOutcome = $outcome['status'] === 'unknown' ? 'unknown:'.($outcome['reason'] ?? 'provider_response_invalid') : $outcome['status'];

        return match ($outcome['status']) {
            'pending' => $this->recordPending($attempt, $outcome),
            'paid', 'failed', 'expired', 'refunded' => $this->recordEvidence($attempt, $outcome),
            default => $this->recordUnknown($attempt, (string) ($outcome['reason'] ?? 'provider_response_invalid'), $mode),
        };
    }

    /** @param array<string, mixed> $outcome */
    private function recordPending(object $attempt, array $outcome): string
    {
        $quarantine = $this->inDomain($attempt->guid, function (object $booking, object $intent, object $row) use ($outcome): bool {
            if (! in_array($row->status, self::OPEN_ATTEMPT_STATUSES, true)) {
                return false;
            }
            $changes = ['last_error_code' => null, 'updated_at' => now()];
            if ($row->provider_reference === null) {
                if ($this->referenceTaken($row, $outcome['provider_reference'])) {
                    return true;
                }
                $changes['provider_reference'] = $outcome['provider_reference'];
            }
            if ($row->status !== 'pending') {
                $changes['status'] = 'pending';
            }
            // The Charge response was lost: show the instruction again while it is still payable (the mapper checked its expiry).
            if ($row->instruction_ciphertext === null && isset($outcome['instruction'])) {
                $changes += ['instruction_ciphertext' => Crypt::encryptString(json_encode($outcome['instruction'], JSON_THROW_ON_ERROR)),
                    'presentation_type' => $outcome['presentation_type']];
            }
            try {
                DB::table('payment_attempts')->where('guid', $row->guid)->update($changes);
            } catch (UniqueConstraintViolationException) {
                return true;
            }

            return false;
        });
        if ($quarantine) {
            $this->quarantineReference($attempt);
        }

        return 'done';
    }

    /**
     * Records a verified, normalized outcome as ledger evidence (append-only, deduplicated on stable facts) and applies it. The
     * transaction id is bound to the attempt first; one that another attempt holds is quarantined instead of crashing.
     *
     * @param  array<string, mixed>  $outcome
     */
    private function recordEvidence(object $attempt, array $outcome): string
    {
        $bound = $this->inDomain($attempt->guid, function (object $booking, object $intent, object $row) use ($outcome): bool {
            if ($row->provider_reference === $outcome['provider_reference']) {
                return true;
            }
            if ($row->provider_reference !== null || $this->referenceTaken($row, $outcome['provider_reference'])) {
                return false;
            }
            try {
                DB::table('payment_attempts')->where('guid', $row->guid)->update(['provider_reference' => $outcome['provider_reference'], 'updated_at' => now()]);
            } catch (UniqueConstraintViolationException) {
                return false;
            }

            return true;
        });
        if (! $bound) {
            $this->quarantineReference($attempt);

            return 'done';
        }
        DB::table('payment_attempts')->where('guid', $attempt->guid)->whereIn('last_error_code', self::REVIEW_REASONS)->update(['last_error_code' => null]);
        $type = match ($outcome['status']) {
            'paid' => 'capture', 'refunded' => 'refund', default => 'status',
        };
        $occurred = CarbonImmutable::parse($outcome['occurred_at'])->utc();
        $snapshot = array_intersect_key($outcome, array_flip(['status', 'merchant_reference', 'provider_reference', 'amount', 'currency', 'method', 'channel']))
            + ['occurred_at' => $occurred->toIso8601String(), 'provider' => 'midtrans', 'environment' => $attempt->environment];
        $key = 'status:'.hash('sha256', implode('|', ['midtrans', $attempt->environment, $outcome['merchant_reference'], $outcome['provider_reference'],
            $outcome['status'], $occurred->toIso8601String(), $outcome['amount']]));
        $eventGuid = (string) Str::uuid();
        DB::table('payment_events')->insertOrIgnore([
            'guid' => $eventGuid, 'payment_intent_guid' => $attempt->payment_intent_guid, 'payment_attempt_guid' => $attempt->guid,
            'provider' => 'midtrans', 'environment' => $attempt->environment, 'provider_event_key' => $key,
            'payload_hash' => $outcome['payload_hash'] ?? hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'verification' => 'verified', 'event_type' => $type, 'disposition' => 'recorded', 'amount' => $outcome['amount'], 'currency' => $outcome['currency'],
            'provider_occurred_at' => $occurred, 'received_at' => now(), 'metadata' => json_encode(['source' => 'midtrans_status', 'outcome' => $snapshot], JSON_THROW_ON_ERROR),
        ]);
        $event = DB::table('payment_events')->where('provider', 'midtrans')->where('environment', $attempt->environment)->where('provider_event_key', $key)->first();
        $stored = json_decode($event->metadata, true, 512, JSON_THROW_ON_ERROR)['outcome'] ?? [];
        ksort($stored);
        ksort($snapshot);
        if ($event->payment_attempt_guid !== $attempt->guid || $stored !== $snapshot) {
            Log::error('Midtrans evidence conflicts with a recorded event.', ['attempt_guid' => $attempt->guid, 'event_guid' => $event->guid]);
            app(PaymentSettlementService::class)->flagAmbiguousProviderOutcome($attempt->guid, 'conflicting_provider_event');

            return 'done';
        }
        Log::info('Midtrans verified event recorded.', ['event_guid' => $event->guid, 'attempt_guid' => $attempt->guid, 'status' => $outcome['status'], 'duplicate' => $event->guid !== $eventGuid]);
        $this->applyEvidence($event->guid);

        return 'done';
    }

    private function recordUnknown(object $attempt, string $reason, string $mode = 'scheduled'): string
    {
        $previous = DB::table('payment_attempts')->where('guid', $attempt->guid)->first(['last_error_code', 'reconcile_count']);
        // A lookup that failed to reach evidence never demotes an instruction that is still valid; only a reservation whose
        // Charge outcome is still unknown becomes `unknown`.
        DB::table('payment_attempts')->where('guid', $attempt->guid)->where('status', 'initiating')->update(['status' => 'unknown', 'updated_at' => now()]);
        DB::table('payment_attempts')->where('guid', $attempt->guid)->whereIn('status', self::OPEN_ATTEMPT_STATUSES)
            ->update(['last_error_code' => substr($reason, 0, 64), 'updated_at' => now()]);
        // Ambiguity seen on an attempt that is already paid (chargeback, partial refund, identity drift) must stay visible to recovery.
        if (in_array($reason, self::REVIEW_REASONS, true)) {
            DB::table('payment_attempts')->where('guid', $attempt->guid)->where('status', 'paid')->update(['last_error_code' => substr($reason, 0, 64), 'updated_at' => now()]);
        }
        // Invalid evidence is retried with backoff, but boundedly: the same invalid answer round after round puts the whole payment
        // under review (the attempt stays open and the quota stays held; nothing is ever paid or released from invalid evidence).
        // Only the scheduler's backed-off rounds count: bursts of owner refreshes or notifications (seconds apart) cannot escalate it.
        $repeatedInvalid = $mode === 'scheduled' && in_array($reason, self::INVALID_REASONS, true) && $previous !== null && $previous->last_error_code === $reason
            && (int) $previous->reconcile_count >= self::INVALID_ESCALATION_ROUNDS;
        if ($repeatedInvalid || in_array($reason, self::REVIEW_REASONS, true)) {
            app(PaymentSettlementService::class)->flagAmbiguousProviderOutcome($attempt->guid, $reason);

            return 'done';
        }

        return 'failed';
    }

    /** Applies a recorded, verified event through the shared settlement service. */
    public function applyEvidence(string $eventGuid): void
    {
        $event = DB::table('payment_events as e')->leftJoin('payment_attempts as a', 'a.guid', '=', 'e.payment_attempt_guid')
            ->where('e.guid', $eventGuid)->first(['e.provider', 'e.disposition', 'e.verification', 'a.method', 'a.channel']);
        if (! $event || $event->provider !== 'midtrans' || $event->disposition !== 'recorded' || $event->verification !== 'verified') {
            return;
        }
        // The settlement service enforces the attempt's own immutable provider/environment binding. The evidence was verified when it
        // was recorded, so a later configuration change must not strand money that was genuinely received.
        $rejectionReason = in_array([$event->method, $event->channel], $this->registry->settlingPairs(), true) ? null : 'snapshot_mismatch';
        app(PaymentSettlementService::class)->apply($eventGuid, $rejectionReason);
    }

    /**
     * Operational escalation for attempts still unresolved after their window while the provider has not proven a terminal
     * outcome. It only marks the attempt and logs once: the attempt stays open, the booking keeps its quota and no new attempt is
     * ever created from here. Returns the number newly escalated.
     */
    public function escalateOverdue(int $limit = 100): int
    {
        $escalated = 0;
        $overdue = DB::table('payment_attempts')->where('provider', 'midtrans')->where('environment', $this->client->environment())
            ->whereIn('status', self::OPEN_ATTEMPT_STATUSES)->whereNull('escalated_at')->where('instruction_expires_at', '<=', now())
            ->orderBy('instruction_expires_at')->orderBy('guid')->limit($limit)->get();
        foreach ($overdue as $attempt) {
            $marked = DB::table('payment_attempts')->where('guid', $attempt->guid)->whereIn('status', self::OPEN_ATTEMPT_STATUSES)->whereNull('escalated_at')
                ->update(['escalated_at' => now(), 'escalation_reason' => substr($attempt->last_error_code ?: 'unresolved_after_expiry', 0, 64), 'updated_at' => now()]);
            if ($marked !== 1) {
                continue;
            }
            $escalated++;
            Log::error('Midtrans payment attempt unresolved after its instruction window; provider status not proven terminal.', [
                'attempt_guid' => $attempt->guid, 'payment_intent_guid' => $attempt->payment_intent_guid, 'status' => $attempt->status,
                'method' => $attempt->method, 'channel' => $attempt->channel, 'instruction_expires_at' => (string) $attempt->instruction_expires_at,
                'reconcile_count' => (int) $attempt->reconcile_count, 'last_error_code' => $attempt->last_error_code,
            ]);
        }

        return $escalated;
    }

    private function referenceTaken(object $attempt, string $reference): bool
    {
        return DB::table('payment_attempts')->where('provider', 'midtrans')->where('environment', $attempt->environment)
            ->where('provider_reference', $reference)->where('guid', '!=', $attempt->guid)->exists();
    }

    /** The provider's transaction id belongs to someone else: no binding, no instruction, no payment; held for review. */
    private function quarantineReference(object $attempt): void
    {
        DB::table('payment_attempts')->where('guid', $attempt->guid)->where('status', 'initiating')->update(['status' => 'unknown', 'updated_at' => now()]);
        DB::table('payment_attempts')->where('guid', $attempt->guid)->whereIn('status', self::OPEN_ATTEMPT_STATUSES)
            ->update(['last_error_code' => 'provider_reference_conflict', 'updated_at' => now()]);
        Log::error('Midtrans returned a transaction id that belongs to another attempt; payment quarantined.', ['attempt_guid' => $attempt->guid]);
        app(PaymentSettlementService::class)->flagAmbiguousProviderOutcome($attempt->guid, 'provider_reference_conflict');
    }

    /**
     * Runs a callback under the domain locks in the plan's order: quota, booking, intent, attempt.
     *
     * @template T
     *
     * @param  Closure(object, object, object): T  $callback
     * @return T
     */
    private function inDomain(string $attemptGuid, Closure $callback): mixed
    {
        $reference = DB::table('payment_attempts as a')->join('payment_intents as i', 'i.guid', '=', 'a.payment_intent_guid')
            ->join('bookings as b', 'b.guid', '=', 'i.booking_guid')->where('a.guid', $attemptGuid)->first(['b.guid as booking_guid', 'b.destination_quota_guid']);
        abort_unless($reference, 404);

        return DB::transaction(function () use ($reference, $attemptGuid, $callback): mixed {
            $booking = $this->lockedBooking($reference->booking_guid, $reference->destination_quota_guid);
            $intent = DB::table('payment_intents')->where('booking_guid', $booking->guid)->lockForUpdate()->first();
            $attempt = DB::table('payment_attempts')->where('guid', $attemptGuid)->lockForUpdate()->first();

            return $callback($booking, $intent, $attempt);
        }, 3);
    }

    private function lockedBooking(string $guid, string $quota): object
    {
        abort_unless(DB::table('destination_quotas')->where('guid', $quota)->lockForUpdate()->first(['guid']), 404);
        $booking = $this->tickets->booking($guid, true);
        if ($booking->destination_quota_guid !== $quota) {
            throw new BookingException('Booking quota changed.', 'payment_needs_review');
        }

        return $booking;
    }

    /** The authoritative start of the deadline is the approval recorded in the status history. */
    private function approvedAt(object $booking): ?CarbonImmutable
    {
        $approved = DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'awaiting_payment')->max('created_at');

        return $approved ? CarbonImmutable::parse($approved, 'UTC') : null;
    }

    private function assertPayable(object $booking): void
    {
        $reason = match (true) {
            $booking->status !== 'awaiting_payment' => 'booking_not_awaiting_payment',
            (int) $booking->total_amount <= 0 => 'booking_not_payable',
            $booking->currency !== 'IDR' => 'unsupported_currency',
            ! $booking->payment_expires_at || now()->gte($booking->payment_expires_at)
                || now()->gte(BookingRules::visitStart($booking->visit_date, $booking->destination_timezone)) => 'payment_window_closed',
            $this->approvedAt($booking) === null => 'booking_not_awaiting_payment',
            default => null,
        };
        if ($reason) {
            throw new BookingException('Booking is not eligible for payment.', $reason);
        }
    }

    private function assertSnapshot(object $booking, object $intent): void
    {
        if ($intent->provider !== 'midtrans') {
            throw new BookingException('Booking has a different payment provider.', 'payment_provider_conflict');
        }
        if ($intent->environment !== $this->client->environment()) {
            throw new BookingException('Payment belongs to another environment.', 'payment_environment_mismatch');
        }
        if ((int) $intent->amount !== (int) $booking->total_amount || $intent->currency !== $booking->currency || $intent->expires_at !== $booking->payment_expires_at) {
            throw new BookingException('Payment snapshot requires investigation.', 'payment_needs_review');
        }
    }

    /** @return array<string, mixed> */
    public function response(object $booking, object $intent, object $attempt): array
    {
        $instruction = ['presentation_type' => 'unavailable'];
        if ($booking->status === 'awaiting_payment' && $intent->status === 'processing' && $attempt->status === 'pending'
            && in_array($attempt->presentation_type, ['virtual_account', 'qr_image'], true) && $intent->environment === $this->client->environment()
            && now()->lt($attempt->instruction_expires_at) && now()->lt($booking->payment_expires_at)) {
            $instruction = $this->client->instruction($attempt);
        }

        return app(PaymentService::class)->invoiceContract($booking, $intent) + [
            'booking_guid' => $booking->guid, 'intent_guid' => $intent->guid, 'attempt_guid' => $attempt->guid,
            'provider' => 'midtrans', 'environment' => $intent->environment, 'test_only' => $intent->environment !== 'production',
            'status' => $attempt->status, 'booking_status' => $booking->status, 'payment_status' => app(PaymentEligibility::class)->status($booking),
            'reason' => match ($attempt->status) {
                'unknown' => $attempt->last_error_code ?? 'payment_pending_reconciliation',
                'failed' => $attempt->last_error_code,
                default => null,
            },
            'amount' => (int) $intent->amount, 'currency' => $intent->currency, 'method_code' => $attempt->method, 'channel' => $attempt->channel,
            // One absolute deadline for every attempt; a retry never extends it.
            'expires_at' => CarbonImmutable::parse($booking->payment_expires_at, 'UTC')->toIso8601String(),
            'server_time' => CarbonImmutable::now()->utc()->toIso8601String(),
            'verification_pending' => app(PaymentService::class)->verificationPending($attempt),
            'escalated' => app(PaymentService::class)->verificationPending($attempt) && $attempt->escalated_at !== null,
            'instruction' => $instruction,
        ];
    }
}
