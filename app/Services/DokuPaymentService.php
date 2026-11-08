<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Jobs\ProcessDokuPaymentEvent;
use App\Models\User;
use App\Services\Doku\DokuChannelRegistry;
use App\Services\Doku\DokuClient;
use App\Support\BookingRules;
use App\Support\PaymentStates;
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

    public function __construct(private DokuClient $client, private TicketService $tickets, private BookingNotificationService $notifications) {}

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
            if ($record->status === 'completed') {
                $stored = json_decode($record->response_body, true, 512, JSON_THROW_ON_ERROR);

                return ['response' => json_decode(Crypt::decryptString($stored['ciphertext']), true, 512, JSON_THROW_ON_ERROR)];
            }
            $intent = DB::table('payment_intents')->where('booking_guid', $guid)->lockForUpdate()->first();
            if ($intent) {
                $this->assertSnapshot($booking, $intent);
                $attempt = DB::table('payment_attempts')->where('payment_intent_guid', $intent->guid)->orderByDesc('attempt_number')->lockForUpdate()->first();
                if ($intent->status === 'needs_review') {
                    throw new BookingException('Payment requires investigation.', 'payment_needs_review');
                }
                if ($attempt && in_array($attempt->status, ['initiating', 'pending', 'unknown', 'paid', 'reversed'], true)) {
                    DB::table('idempotency_records')->where('guid', $record->guid)->update(['response_reference' => $attempt->guid]);

                    return ['response' => $this->response($booking, $intent, $attempt)];
                }
            }
            $this->assertPayable($booking);
            $definition = app(DokuChannelRegistry::class)->find($input['method_code'], $input['channel']);
            if ($reason = $definition ? app(DokuChannelRegistry::class)->reason($definition['code']) : 'channel_unavailable') {
                throw new BookingException('Payment channel is unavailable.', $reason);
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
                'status' => 'initiating', 'presentation_type' => 'unavailable', 'instruction_expires_at' => $intent->expires_at,
                'started_at' => now(), 'next_reconcile_at' => now()->addMinutes(2), 'created_at' => now(), 'updated_at' => now(),
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
            // A refresh may finish before create; keep its valid instructions without replacing a final outcome.
            $recoverInstructions = in_array($attempt->status, ['pending', 'unknown'], true) && $attempt->instruction_ciphertext === null
                && ($result['status'] ?? null) === 'pending'
                && ($attempt->provider_reference === null || ! isset($result['provider_reference']) || $attempt->provider_reference === $result['provider_reference']);
            if ($attempt->status === 'initiating' || $recoverInstructions) {
                $status = in_array($result['status'] ?? null, ['pending', 'failed'], true) ? $result['status'] : 'unknown';
                $expiry = isset($result['expires_at']) ? CarbonImmutable::parse($result['expires_at'])->utc() : null;
                $presentation = $result['presentation_type'] ?? 'virtual_account';
                $instruction = $result['instruction'] ?? [];
                $complete = match ($presentation) {
                    'virtual_account' => ! empty($instruction['va_number']) && ! empty($result['provider_reference']),
                    'qr_payload' => ! empty($instruction['qr_content']) && ! empty($result['provider_reference']),
                    'ewallet_redirect' => ! empty($instruction['redirect_url']),
                    default => false,
                };
                if ($status === 'pending' && (! $expiry || $expiry->gt($booking->payment_expires_at) || $expiry->lte(now()) || ! $complete)) {
                    $status = 'unknown';
                }
                DB::table('payment_attempts')->where('guid', $attempt->guid)->update([
                    'status' => $status, 'provider_reference' => $result['provider_reference'] ?? $attempt->provider_reference,
                    'instruction_ciphertext' => isset($result['instruction']) ? Crypt::encryptString(json_encode($result['instruction'], JSON_THROW_ON_ERROR)) : null,
                    'presentation_type' => $status === 'pending' ? $presentation : 'unavailable',
                    'instruction_expires_at' => $expiry && $expiry->lt($booking->payment_expires_at) ? $expiry : $booking->payment_expires_at,
                    'last_error_code' => $status === 'unknown' ? ($result['reason'] ?? 'provider_response_invalid') : ($status === 'failed' ? ($result['reason'] ?? 'provider_create_failed') : null),
                    'finished_at' => $status === 'failed' ? now() : null,
                    'next_reconcile_at' => $status === 'failed' ? null : $attempt->next_reconcile_at, 'updated_at' => now(),
                ]);
                if ($status === 'failed') {
                    DB::table('payment_intents')->where('guid', $intent->guid)->update(['status' => 'requires_payment', 'updated_at' => now()]);
                }
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

        return [
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
        $reference = DB::table('payment_events as e')->join('payment_intents as i', 'i.guid', '=', 'e.payment_intent_guid')
            ->join('bookings as b', 'b.guid', '=', 'i.booking_guid')->where('e.guid', $eventGuid)->first(['b.guid', 'b.destination_quota_guid']);
        if (! $reference) {
            return;
        }
        DB::transaction(function () use ($eventGuid, $reference): void {
            $booking = $this->lockedBooking($reference->guid, $reference->destination_quota_guid);
            $intent = DB::table('payment_intents')->where('booking_guid', $booking->guid)->lockForUpdate()->first();
            $eventRef = DB::table('payment_events')->where('guid', $eventGuid)->first();
            $attempt = DB::table('payment_attempts')->where('guid', $eventRef->payment_attempt_guid)->lockForUpdate()->first();
            $event = DB::table('payment_events')->where('guid', $eventGuid)->lockForUpdate()->first();
            if ($event->disposition !== 'recorded' || $event->verification !== 'verified') {
                return;
            }
            if ($intent->environment !== $this->client->environment() || $event->environment !== $intent->environment
                || $attempt->environment !== $intent->environment || $intent->provider !== 'doku' || $attempt->provider !== 'doku' || $event->provider !== 'doku') {
                DB::table('payment_events')->where('guid', $event->guid)->update(['disposition' => 'quarantined']);

                return;
            }
            $outcome = json_decode($event->metadata, true, 512, JSON_THROW_ON_ERROR)['outcome'];
            if ($event->provider_occurred_at && CarbonImmutable::parse($event->provider_occurred_at)->gt(now())
                && CarbonImmutable::parse($event->provider_occurred_at)->lte(now()->addMinutes(5))) {
                return;
            }
            $matches = $intent->provider === 'doku' && $attempt->provider === 'doku' && $event->provider === 'doku'
                && $intent->environment === $this->client->environment() && $attempt->environment === $intent->environment && $event->environment === $intent->environment
                && $attempt->payment_intent_guid === $intent->guid && $event->payment_intent_guid === $intent->guid
                && in_array([$attempt->method, $attempt->channel], app(DokuChannelRegistry::class)->settlingPairs(), true) && ! empty($outcome['provider_reference'])
                && $attempt->merchant_reference === $outcome['merchant_reference']
                && ($attempt->provider_reference === null || $attempt->provider_reference === ($outcome['provider_reference'] ?? null))
                && (int) $attempt->amount === (int) $intent->amount && (int) $intent->amount === (int) $booking->total_amount
                && (int) ($event->amount ?? 0) === (int) $attempt->amount && $attempt->currency === 'IDR'
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
                if ($attempt->verified_paid_at === null) {
                    $this->review($booking, $intent, $event, 'adjustment_without_capture');

                    return;
                }
                DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['status' => 'reversed', 'reversed_at' => $event->provider_occurred_at, 'next_reconcile_at' => null, 'updated_at' => now()]);
                $this->review($booking, $intent, $event, 'provider_adjustment', 'applied');
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
        if ($attempt->verified_paid_at !== null) {
            DB::table('payment_events')->where('guid', $event->guid)->update(['disposition' => 'duplicate_capture']);

            return;
        }
        DB::table('payment_attempts')->where('guid', $attempt->guid)->update([
            'status' => 'paid', 'verified_paid_at' => $event->provider_occurred_at, 'finished_at' => now(), 'next_reconcile_at' => null,
            'provider_reference' => $attempt->provider_reference ?? $outcome['provider_reference'], 'updated_at' => now(),
        ]);
        $eligible = in_array($booking->status, ['awaiting_payment', 'payment_verification', 'payment_review'], true)
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
            'old_status' => $booking->status, 'new_status' => 'confirmed', 'reason' => 'Verified DOKU '.$intent->environment.' payment.', 'created_at' => now()]);
        DB::table('payment_events')->where('guid', $event->guid)->update(['disposition' => 'applied']);
        $this->notifications->record($booking->guid, $booking->user_guid, $intent->environment === 'sandbox' ? 'sandbox_paid' : 'doku_paid');
    }

    private function review(object $booking, object $intent, object $event, string $reason, string $disposition = 'quarantined'): void
    {
        DB::table('payment_intents')->where('guid', $intent->guid)->update(['status' => 'needs_review', 'updated_at' => now()]);
        $metadata = json_decode($event->metadata, true, 512, JSON_THROW_ON_ERROR);
        DB::table('payment_events')->where('guid', $event->guid)->update(['disposition' => $disposition, 'metadata' => json_encode($metadata + ['reason' => $reason], JSON_THROW_ON_ERROR)]);
        DB::table('booking_tickets')->where('booking_guid', $booking->guid)->whereNull('checked_in_at')->whereNotNull('qr_token_hash')
            ->whereNull('qr_revoked_at')->update(['qr_revoked_at' => now(), 'updated_at' => now()]);
        $this->notifications->record($booking->guid, $booking->user_guid, 'payment_review');
        Log::warning('DOKU payment needs review.', ['intent_guid' => $intent->guid, 'event_guid' => $event->guid, 'reason' => $reason]);
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
