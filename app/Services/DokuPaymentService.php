<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Jobs\ProcessDokuPaymentEvent;
use App\Models\User;
use App\Services\Doku\DokuChannelRegistry;
use App\Services\Doku\DokuClient;
use App\Support\BookingRules;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class DokuPaymentService
{
    /** Attempt statuses that can still change at the provider. */
    private const OPEN_ATTEMPT_STATUSES = ['initiating', 'pending', 'unknown'];

    private const REFRESH_MIN_INTERVAL_SECONDS = 10;

    public function __construct(private DokuClient $client, private TicketService $tickets) {}

    /** @param array{idempotency_key: string, method_code: string, channel: string} $input */
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
                'guid' => (string) Str::uuid(), 'user_guid' => $actor->guid, 'operation' => 'doku_payment.initiate',
                'key' => $input['idempotency_key'], 'request_hash' => $hash, 'status' => 'processing',
                'expires_at' => now()->addDays(BookingRules::IDEMPOTENCY_DAYS), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $record = DB::table('idempotency_records')->where('user_guid', $actor->guid)->where('operation', 'doku_payment.initiate')
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
                // Replays are rebuilt from live state so an expired instruction (VA/QR/redirect) is never shown again.
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
            $definition = app(DokuChannelRegistry::class)->find($input['method_code'], $input['channel']);
            if ($reason = $definition ? app(DokuChannelRegistry::class)->reason($definition['code']) : 'channel_unavailable') {
                throw new BookingException('Payment channel is unavailable.', $reason);
            }
            if ($reason = $this->client->expiryWindowReason($definition['code'], $expiresAt)) {
                throw new BookingException('Payment instruction window is unavailable.', $reason);
            }
            if (! $intent) {
                $intent = (object) [
                    'guid' => (string) Str::uuid(), 'booking_guid' => $guid, 'provider' => 'doku', 'environment' => $this->client->environment(),
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
            $attempt = (object) [
                'guid' => (string) Str::uuid(), 'payment_intent_guid' => $intent->guid, 'provider' => 'doku', 'environment' => $intent->environment,
                'attempt_number' => $number + 1, 'method' => $input['method_code'], 'channel' => $input['channel'],
                'merchant_reference' => (string) Str::uuid(), 'provider_request_id' => (string) random_int(100000000000000000, 999999999999999999),
                'request_hash' => $hash, 'amount' => $intent->amount, 'currency' => $intent->currency,
                'status' => 'initiating', 'presentation_type' => 'unavailable', 'instruction_expires_at' => $expiresAt,
                'started_at' => $startedAt, 'next_reconcile_at' => now()->addMinutes(2), 'created_at' => now(), 'updated_at' => now(),
            ];
            DB::table('payment_attempts')->insert((array) $attempt);
            DB::table('payment_intents')->where('guid', $intent->guid)->update(['status' => 'processing', 'updated_at' => now()]);
            DB::table('idempotency_records')->where('guid', $record->guid)->update(['response_reference' => $attempt->guid, 'updated_at' => now()]);

            return ['attempt' => $attempt, 'booking' => $booking];
        }, 3);
        if (isset($reservation['response'])) {
            return $reservation['response'];
        }
        // The durable reservation is committed before the provider can accept a payment.
        $started = hrtime(true);
        try {
            $result = $this->client->create($reservation['attempt'], $reservation['booking']);
        } catch (Throwable $e) {
            // Adapters classify provider failures themselves, so anything reaching here is an internal fault of unknown phase.
            $this->client->recordFailure($this->client->diagnosticContext($reservation['attempt']), 'internal', 'doku_internal_exception', ['exception_class' => $e::class]);
            $result = ['status' => 'unknown', 'reason' => 'provider_unavailable'];
        }
        Log::info('DOKU initiation resolved.', ['attempt_guid' => $reservation['attempt']->guid, 'provider' => 'doku',
            'environment' => $reservation['attempt']->environment, 'channel' => $reservation['attempt']->channel,
            'status' => $result['status'], 'latency_ms' => (int) ((hrtime(true) - $started) / 1000000)]);

        return $this->saveInitiation($reservation['attempt'], $reservation['booking'], $result);
    }

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
            $complete = match ($presentation) {
                'virtual_account' => ! empty($instruction['va_number']) && ! empty($result['provider_reference']),
                'qr_payload' => ! empty($instruction['qr_content']) && ! empty($result['provider_reference']),
                'ewallet_redirect' => ! empty($instruction['redirect_url']),
                default => false,
            };
            // A refresh may finish before create; keep its valid instructions without replacing a final outcome.
            $recoverInstructions = in_array($attempt->status, ['pending', 'unknown'], true) && $attempt->instruction_ciphertext === null
                && ($result['status'] ?? null) === 'pending'
                && ($attempt->provider_reference === null || ! isset($result['provider_reference']) || $attempt->provider_reference === $result['provider_reference']);
            if ($attempt->status === 'initiating' || $recoverInstructions) {
                $status = in_array($result['status'] ?? null, ['pending', 'failed'], true) ? $result['status'] : 'unknown';
                if ($status === 'pending' && (! $expiry || $expiry->gt($attempt->instruction_expires_at) || $expiry->lte(now()) || ! $complete)) {
                    $status = 'unknown';
                }
                DB::table('payment_attempts')->where('guid', $attempt->guid)->update([
                    'status' => $status, 'provider_reference' => $result['provider_reference'] ?? $attempt->provider_reference,
                    'instruction_ciphertext' => isset($result['instruction']) ? Crypt::encryptString(json_encode($result['instruction'], JSON_THROW_ON_ERROR)) : null,
                    'presentation_type' => $status === 'pending' ? $presentation : 'unavailable',
                    'instruction_expires_at' => $expiry && $expiry->lt($attempt->instruction_expires_at) ? $expiry : $attempt->instruction_expires_at,
                    'last_error_code' => $status === 'unknown' ? ($result['reason'] ?? 'provider_response_invalid') : ($status === 'failed' ? ($result['reason'] ?? 'provider_create_failed') : null),
                    'finished_at' => $status === 'failed' ? now() : null,
                    'next_reconcile_at' => $status === 'failed' ? null : $attempt->next_reconcile_at, 'updated_at' => now(),
                ]);
                if ($status === 'failed') {
                    DB::table('payment_intents')->where('guid', $intent->guid)->update(['status' => 'requires_payment', 'updated_at' => now()]);
                }
            } elseif ($attempt->status === 'paid' && ($result['status'] ?? null) === 'pending' && $complete
                && ! empty($result['provider_reference']) && $attempt->provider_reference === $result['provider_reference']
                && $expiry && $expiry->gt(now()) && $expiry->lt($attempt->instruction_expires_at)) {
                DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['instruction_expires_at' => $expiry, 'updated_at' => now()]);
            }
            $response = $this->response($booking, DB::table('payment_intents')->where('guid', $intent->guid)->first(), DB::table('payment_attempts')->where('guid', $attempt->guid)->first());
            DB::table('idempotency_records')->where('operation', 'doku_payment.initiate')->where('response_reference', $attempt->guid)
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

    private function assertPayable(object $booking): void
    {
        $reason = match (true) {
            $booking->status !== 'awaiting_payment' => 'booking_not_awaiting_payment',
            (int) $booking->total_amount <= 0 => 'booking_not_payable',
            $booking->currency !== 'IDR' => 'unsupported_currency',
            ! $booking->payment_expires_at || now()->gte($booking->payment_expires_at)
                || now()->gte(BookingRules::visitStart($booking->visit_date, $booking->destination_timezone)) => 'payment_window_closed',
            ! DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'awaiting_payment')->exists() => 'booking_not_awaiting_payment',
            default => null,
        };
        if ($reason) {
            throw new BookingException('Booking is not eligible for payment.', $reason);
        }
    }

    private function assertSnapshot(object $booking, object $intent): void
    {
        if ($intent->provider !== 'doku') {
            throw new BookingException('Booking has a different payment provider.', 'payment_provider_conflict');
        }
        if ($intent->environment !== $this->client->environment()) {
            throw new BookingException('Payment belongs to another environment.', 'payment_environment_mismatch');
        }
        if ((int) $intent->amount !== (int) $booking->total_amount || $intent->currency !== $booking->currency || $intent->expires_at !== $booking->payment_expires_at) {
            throw new BookingException('Payment snapshot requires investigation.', 'payment_needs_review');
        }
    }

    public function response(object $booking, object $intent, object $attempt): array
    {
        $instruction = ['presentation_type' => 'unavailable'];
        if ($booking->status === 'awaiting_payment' && $intent->status === 'processing' && $attempt->status === 'pending'
            && in_array($attempt->presentation_type, ['virtual_account', 'qr_payload', 'ewallet_redirect'], true) && $intent->environment === $this->client->environment()
            && now()->lt($attempt->instruction_expires_at) && now()->lt($booking->payment_expires_at)) {
            $instruction = $this->client->instruction($attempt);
        }

        return app(PaymentService::class)->invoiceContract($booking, $intent) + [
            'booking_guid' => $booking->guid, 'intent_guid' => $intent->guid, 'attempt_guid' => $attempt->guid,
            'provider' => 'doku', 'environment' => $intent->environment, 'test_only' => $intent->environment !== 'production',
            'status' => $attempt->status, 'booking_status' => $booking->status, 'payment_status' => app(PaymentEligibility::class)->status($booking),
            'reason' => match ($attempt->status) {
                'unknown' => $attempt->last_error_code ?? 'payment_pending_reconciliation',
                'failed' => $attempt->last_error_code,
                default => null,
            },
            'amount' => (int) $intent->amount, 'currency' => $intent->currency, 'method_code' => $attempt->method, 'channel' => $attempt->channel,
            'expires_at' => CarbonImmutable::parse($attempt->instruction_expires_at, 'UTC')->toIso8601String(),
            'verification_pending' => app(PaymentService::class)->verificationPending($attempt),
            'escalated' => app(PaymentService::class)->verificationPending($attempt) && $attempt->escalated_at !== null,
            'instruction' => $instruction,
        ];
    }

    /** Accept only normalized outcomes from the verified provider boundary. */
    public function receive(array $outcome): string
    {
        $attempt = DB::table('payment_attempts')->where('provider', 'doku')->where('environment', $this->client->environment())
            ->where('merchant_reference', $outcome['merchant_reference'])->first();
        if (! $attempt) {
            throw new BookingException('Unknown payment reference or environment.', 'payment_environment_mismatch');
        }
        if ((isset($outcome['channel']) && $outcome['channel'] !== $attempt->channel) || (isset($outcome['method']) && $outcome['method'] !== $attempt->method)) {
            throw new BookingException('Provider event belongs to another channel.', 'payment_needs_review');
        }
        $type = match ($outcome['status']) {
            'paid' => 'capture', 'reversed' => 'reversal', 'refunded' => 'refund', default => 'status'
        };
        $snapshot = array_intersect_key($outcome, array_flip(['status', 'merchant_reference', 'provider_reference', 'amount', 'currency', 'occurred_at', 'method', 'channel']));
        if (isset($snapshot['occurred_at'])) {
            $snapshot['occurred_at'] = CarbonImmutable::parse($snapshot['occurred_at'])->utc()->toIso8601String();
        }
        $eventGuid = (string) Str::uuid();
        DB::table('payment_events')->insertOrIgnore([
            'guid' => $eventGuid, 'payment_intent_guid' => $attempt->payment_intent_guid, 'payment_attempt_guid' => $attempt->guid,
            'provider' => 'doku', 'environment' => $attempt->environment, 'provider_event_key' => $outcome['event_key'],
            'payload_hash' => $outcome['payload_hash'], 'verification' => 'verified', 'event_type' => $type, 'disposition' => 'recorded',
            'amount' => $outcome['amount'] ?? null, 'currency' => $outcome['currency'] ?? null,
            'provider_occurred_at' => isset($outcome['occurred_at']) ? CarbonImmutable::parse($outcome['occurred_at'])->utc() : null,
            'received_at' => now(), 'metadata' => json_encode(['source' => 'doku_snap', 'outcome' => $snapshot], JSON_THROW_ON_ERROR),
        ]);
        $event = DB::table('payment_events')->where('provider', 'doku')->where('environment', $attempt->environment)->where('provider_event_key', $outcome['event_key'])->first();
        $storedSnapshot = json_decode($event->metadata, true, 512, JSON_THROW_ON_ERROR)['outcome'];
        ksort($storedSnapshot);
        ksort($snapshot);
        if ($event->payment_attempt_guid !== $attempt->guid || $storedSnapshot !== $snapshot) {
            throw new BookingException('Conflicting provider event.', 'payment_needs_review');
        }
        Log::info('DOKU verified event received.', ['event_guid' => $event->guid, 'attempt_guid' => $attempt->guid,
            'provider' => 'doku', 'environment' => $attempt->environment, 'duplicate' => $event->guid !== $eventGuid]);
        try {
            ProcessDokuPaymentEvent::dispatch($event->guid);
        } catch (Throwable) {
            Log::warning('DOKU event awaits scheduled replay.', ['event_guid' => $event->guid]);
        }

        return $event->guid;
    }

    public function apply(string $eventGuid): void
    {
        $event = DB::table('payment_events as e')->leftJoin('payment_attempts as a', 'a.guid', '=', 'e.payment_attempt_guid')
            ->where('e.guid', $eventGuid)->first(['e.provider', 'e.environment', 'e.disposition', 'e.verification', 'a.method', 'a.channel']);
        if (! $event || $event->disposition !== 'recorded' || $event->verification !== 'verified') {
            return;
        }
        if ($event->provider !== 'doku' || $event->environment !== $this->client->environment()) {
            DB::table('payment_events')->where('guid', $eventGuid)->where('disposition', 'recorded')->update(['disposition' => 'quarantined']);

            return;
        }
        $rejectionReason = in_array([$event->method, $event->channel], app(DokuChannelRegistry::class)->settlingPairs(), true) ? null : 'snapshot_mismatch';
        app(PaymentSettlementService::class)->apply($eventGuid, $rejectionReason);
    }

    /**
     * Payment QR for the owner's active QRIS attempt, rendered from the persisted provider payload. It is not an entry credential
     * and is served only while the same instruction would still be shown; everything else is a plain 404.
     */
    public function qrisSvg(User $owner, string $guid, string $attemptGuid): string
    {
        $booking = app(PaymentService::class)->ownerBooking($owner, $guid);
        $intent = DB::table('payment_intents')->where('booking_guid', $guid)->first();
        $attempt = $intent ? DB::table('payment_attempts')->where('guid', $attemptGuid)->where('payment_intent_guid', $intent->guid)->first() : null;
        abort_unless($attempt && $attempt->provider === 'doku' && $attempt->method === 'qris' && $attempt->presentation_type === 'qr_payload'
            && $attempt->status === 'pending' && $intent->status === 'processing' && $booking->status === 'awaiting_payment'
            && $intent->environment === $this->client->environment() && $attempt->environment === $intent->environment
            && now()->lt($attempt->instruction_expires_at) && now()->lt($booking->payment_expires_at), 404);
        $payload = $this->client->instruction($attempt)['qr_content'] ?? null;
        abort_unless(is_string($payload) && $payload !== '' && strlen($payload) <= 2048, 404);

        return (new Writer(new ImageRenderer(new RendererStyle(360, 4), new SvgImageBackEnd)))->writeString($payload, 'UTF-8', ErrorCorrectionLevel::M());
    }

    /**
     * Owner-triggered refresh used when the customer returns from a wallet or resumes the app. It only asks DOKU
     * (authenticated status inquiry) and applies the verified outcome; a redirect or return URL is never proof.
     * Parallel or rapid calls collapse into one provider query through the reconciliation claim and the minimum interval.
     */
    public function refresh(User $owner, string $guid, string $attemptGuid): void
    {
        app(PaymentService::class)->ownerBooking($owner, $guid);
        $attempt = DB::table('payment_attempts as a')->join('payment_intents as i', 'i.guid', '=', 'a.payment_intent_guid')
            ->where('i.booking_guid', $guid)->where('a.guid', $attemptGuid)->where('a.provider', 'doku')
            ->where('a.environment', $this->client->environment())->first(['a.guid', 'a.status', 'a.method']);
        abort_unless($attempt, 404);
        if (! in_array($attempt->status, self::OPEN_ATTEMPT_STATUSES, true)) {
            return;
        }
        $minimumInterval = $attempt->method === 'ewallet' ? 60 : self::REFRESH_MIN_INTERVAL_SECONDS;
        DB::table('payment_attempts')->where('guid', $attempt->guid)->whereIn('status', self::OPEN_ATTEMPT_STATUSES)
            ->where(fn ($query) => $query->whereNull('last_reconciled_at')->orWhere('last_reconciled_at', '<=', now()->subSeconds($minimumInterval)))
            ->update(['next_reconcile_at' => now()->subSecond()]);
        $this->reconcile($attempt->guid);
        foreach (DB::table('payment_events')->where('payment_attempt_guid', $attempt->guid)->where('disposition', 'recorded')->pluck('guid') as $eventGuid) {
            $this->apply($eventGuid);
        }
    }

    /**
     * Operational escalation for attempts that are past their instruction window while the provider has not proven a terminal
     * outcome. Escalation only marks the attempt and logs once: the attempt stays open (reconciliation keeps polling it), the
     * booking keeps holding its quota, and no new attempt is ever created from here. Returns the number newly escalated.
     */
    public function escalateOverdue(int $limit = 100): int
    {
        $escalated = 0;
        $overdue = DB::table('payment_attempts')->where('provider', 'doku')->where('environment', $this->client->environment())
            ->whereIn('status', self::OPEN_ATTEMPT_STATUSES)->whereNull('escalated_at')->where('instruction_expires_at', '<=', now())
            ->orderBy('instruction_expires_at')->orderBy('guid')->limit($limit)->get();
        foreach ($overdue as $attempt) {
            $marked = DB::table('payment_attempts')->where('guid', $attempt->guid)->whereIn('status', self::OPEN_ATTEMPT_STATUSES)->whereNull('escalated_at')
                ->update(['escalated_at' => now(), 'escalation_reason' => substr($attempt->last_error_code ?: 'unresolved_after_expiry', 0, 64), 'updated_at' => now()]);
            if ($marked !== 1) {
                continue;
            }
            $escalated++;
            Log::error('DOKU payment attempt unresolved after its instruction window; provider status not proven terminal.', [
                'attempt_guid' => $attempt->guid, 'payment_intent_guid' => $attempt->payment_intent_guid, 'status' => $attempt->status,
                'method' => $attempt->method, 'channel' => $attempt->channel, 'instruction_expires_at' => (string) $attempt->instruction_expires_at,
                'reconcile_count' => (int) $attempt->reconcile_count, 'last_error_code' => $attempt->last_error_code,
            ]);
        }

        return $escalated;
    }

    public function reconcile(string $guid): void
    {
        if (! $this->client->configured()) {
            return;
        }
        $claimed = DB::table('payment_attempts')->where('guid', $guid)->where('provider', 'doku')->where('environment', $this->client->environment())
            ->whereIn('status', ['initiating', 'pending', 'unknown'])->where('next_reconcile_at', '<=', now())
            ->update(['next_reconcile_at' => now()->addMinutes(10), 'last_reconciled_at' => now(), 'reconcile_count' => DB::raw('reconcile_count + 1')]);
        if (! $claimed) {
            return;
        }
        $attempt = DB::table('payment_attempts')->where('guid', $guid)->first();
        try {
            $outcome = $this->client->status($attempt);
            if ($outcome['status'] === 'pending') {
                DB::table('payment_attempts')->where('guid', $guid)->whereIn('status', ['initiating', 'pending', 'unknown'])
                    ->update(['status' => 'pending', 'last_error_code' => null, 'updated_at' => now()]);
            } elseif (isset($outcome['merchant_reference'], $outcome['amount'], $outcome['currency'], $outcome['occurred_at']) && $outcome['status'] !== 'unknown') {
                $outcome['event_key'] = $outcome['event_key'] ?? hash('sha256', $guid.':'.$outcome['status'].':'.$outcome['occurred_at']);
                $outcome['payload_hash'] = $outcome['payload_hash'] ?? hash('sha256', json_encode($outcome, JSON_THROW_ON_ERROR));
                $this->receive($outcome);
            } else {
                DB::table('payment_attempts')->where('guid', $guid)->whereIn('status', ['initiating', 'pending', 'unknown'])
                    ->update(['status' => 'unknown', 'last_error_code' => $outcome['reason'] ?? 'payment_pending_reconciliation']);
            }
        } catch (Throwable) {
            DB::table('payment_attempts')->where('guid', $guid)->update(['last_error_code' => 'reconciliation_unavailable']);
        }
        DB::table('payment_attempts')->where('guid', $guid)->whereIn('status', ['initiating', 'pending', 'unknown'])
            ->update(['next_reconcile_at' => now()->addSeconds(min($attempt->method === 'virtual_account' ? 3600 : 180, 60 * 2 ** min((int) $attempt->reconcile_count, 5)) + random_int(0, 30))]);
    }
}
