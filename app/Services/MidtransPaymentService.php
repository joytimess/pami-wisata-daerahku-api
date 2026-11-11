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

    private const REFRESH_MIN_INTERVAL_SECONDS = 10;

    private const NOTIFICATION_MIN_INTERVAL_SECONDS = 5;

    /**
     * Provider evidence that is neither payable nor safely retryable: the payment is held for a human instead of being retried
     * or guessed. (`merchant_mismatch` here is the PROVIDER's merchant differing from the bound one.)
     */
    private const REVIEW_REASONS = ['partial_adjustment_review', 'fraud_status_review', 'merchant_mismatch', 'transaction_mismatch'];

    /** Evidence that does not match the stored attempt. Retried a bounded number of consecutive rounds, then reviewed. */
    private const INVALID_REASONS = ['provider_response_invalid', 'provider_expiry_invalid'];

    private const INVALID_ESCALATION_ROUNDS = 5;

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
        return DB::transaction(function () use ($attempt, $booking, $result): array {
            $booking = $this->lockedBooking($booking->guid, $booking->destination_quota_guid);
            $intent = DB::table('payment_intents')->where('guid', $attempt->payment_intent_guid)->lockForUpdate()->first();
            $attempt = DB::table('payment_attempts')->where('guid', $attempt->guid)->lockForUpdate()->first();
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
            // A settlement or status check may finish before the Charge response: a final outcome is never replaced by a pending one.
            if ($attempt->status === 'initiating') {
                $status = in_array($result['status'] ?? null, ['pending', 'failed'], true) ? $result['status'] : 'unknown';
                if ($status === 'pending' && (! $expiry || $expiry->gt($attempt->instruction_expires_at) || $expiry->lte(now()) || ! $complete)) {
                    $status = 'unknown';
                }
                DB::table('payment_attempts')->where('guid', $attempt->guid)->update([
                    'status' => $status, 'provider_reference' => $result['provider_reference'] ?? $attempt->provider_reference,
                    'instruction_ciphertext' => $status === 'pending' ? Crypt::encryptString(json_encode($instruction, JSON_THROW_ON_ERROR)) : null,
                    'presentation_type' => $status === 'pending' ? $presentation : 'unavailable',
                    'last_error_code' => $status === 'unknown' ? ($result['reason'] ?? 'provider_response_invalid') : ($status === 'failed' ? ($result['reason'] ?? 'provider_create_failed') : null),
                    'finished_at' => $status === 'failed' ? now() : null,
                    'next_reconcile_at' => $status === 'failed' ? null : $attempt->next_reconcile_at, 'updated_at' => now(),
                ]);
                if ($status === 'failed') {
                    DB::table('payment_intents')->where('guid', $intent->guid)->update(['status' => 'requires_payment', 'updated_at' => now()]);
                }
            }
            $response = $this->response($booking, DB::table('payment_intents')->where('guid', $intent->guid)->first(), DB::table('payment_attempts')->where('guid', $attempt->guid)->first());
            DB::table('idempotency_records')->where('operation', self::OPERATION)->where('response_reference', $attempt->guid)
                ->where('status', 'processing')->update(['status' => 'completed', 'response_body' => json_encode([
                    'ciphertext' => Crypt::encryptString(json_encode($response, JSON_THROW_ON_ERROR)),
                ], JSON_THROW_ON_ERROR), 'updated_at' => now()]);

            return $response;
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
