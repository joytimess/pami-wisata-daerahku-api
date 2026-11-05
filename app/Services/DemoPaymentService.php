<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Models\User;
use App\Support\BookingRules;
use App\Support\PaymentStates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoPaymentService
{
    public function __construct(private PaymentGateway $gateway, private TicketService $tickets, private PaymentEligibility $eligibility, private BookingNotificationService $notifications) {}

    /** @param array{idempotency_key: string} $input */
    public function initiate(User $owner, string $guid, array $input): array
    {
        return $this->execute($owner, $guid, $input, false);
    }

    /** @param array{idempotency_key: string, attempt_guid: string, outcome: string} $input */
    public function simulate(User $owner, string $guid, array $input): array
    {
        abort_unless(in_array($input['outcome'], ['success', 'failed', 'expired'], true), 422);

        return $this->execute($owner, $guid, $input, true);
    }

    private function execute(User $owner, string $guid, array $input, bool $simulate): array
    {
        abort_unless($this->gateway->demoEnabled(), 404);
        $quotaGuid = DB::table('bookings')->where('guid', $guid)->value('destination_quota_guid');
        abort_unless($quotaGuid, 404);

        return DB::transaction(function () use ($owner, $guid, $input, $simulate, $quotaGuid): array {
            // Acquire the parent lock before any consistent read establishes a MySQL snapshot.
            abort_unless(DB::table('destination_quotas')->where('guid', $quotaGuid)->lockForUpdate()->first(['guid']), 404);
            $booking = $this->tickets->booking($guid, true);
            if ($booking->destination_quota_guid !== $quotaGuid) {
                throw new BookingException('The booking quota changed; retry from its current state.', 'demo_payment_conflict');
            }
            $actor = User::whereKey($owner->guid)->sharedLock()->first();
            abort_unless($actor && $actor->status === 'active' && (int) $actor->role === 0 && $booking->user_guid === $actor->guid, 404);
            abort_unless($this->gateway->demoEnabled(), 404);
            $operation = $simulate ? 'demo_payment.simulate' : 'demo_payment.initiate';
            $payload = ['booking_guid' => $guid];
            if ($simulate) {
                $payload += ['attempt_guid' => $input['attempt_guid'], 'outcome' => $input['outcome']];
            }
            $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            DB::table('idempotency_records')->insertOrIgnore([
                'guid' => (string) Str::uuid(), 'user_guid' => $actor->guid, 'operation' => $operation,
                'key' => $input['idempotency_key'], 'request_hash' => $hash, 'status' => 'processing',
                'expires_at' => now()->addDays(BookingRules::IDEMPOTENCY_DAYS), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $record = DB::table('idempotency_records')->where('user_guid', $actor->guid)->where('operation', $operation)
                ->where('key', $input['idempotency_key'])->lockForUpdate()->first();
            if (! hash_equals($record->request_hash, $hash)) {
                throw new BookingException('Idempotency key already used with different input.', 'idempotency_conflict');
            }
            if (now()->gte($record->expires_at)) {
                throw new BookingException('The DEMO payment key has expired; check the booking status.', 'idempotency_key_expired');
            }
            if ($record->status === 'completed') {
                return json_decode($record->response_body, true, 512, JSON_THROW_ON_ERROR);
            }
            $intent = DB::table('payment_intents')->where('booking_guid', $guid)->lockForUpdate()->first();
            if ($intent) {
                $this->assertSnapshot($booking, $intent);
            }
            $response = $simulate ? $this->applyOutcome($booking, $actor, $intent, $input)
                : $this->startAttempt($booking, $intent, $hash);
            DB::table('idempotency_records')->where('guid', $record->guid)->update([
                'status' => 'completed', 'response_reference' => $response['attempt_guid'],
                'response_body' => json_encode($response, JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);

            return $response;
        }, 3);
    }

    private function assertPayable(object $booking, CarbonImmutable $instant): void
    {
        if ($booking->status !== 'awaiting_payment' || (int) $booking->total_amount <= 0 || $booking->currency !== 'IDR'
            || ! $booking->payment_expires_at || $instant->gte($booking->payment_expires_at)
            || $instant->gte(BookingRules::visitStart($booking->visit_date, $booking->destination_timezone))
            || ! DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'awaiting_payment')->exists()) {
            throw new BookingException('The booking is not eligible for a DEMO payment.', 'demo_payment_ineligible');
        }
    }

    private function assertSnapshot(object $booking, object $intent): void
    {
        if ($intent->provider !== 'demo' || $intent->environment !== 'demo' || $intent->currency !== $booking->currency
            || (int) $intent->amount !== (int) $booking->total_amount || $intent->expires_at !== $booking->payment_expires_at) {
            throw new BookingException('The payment intent does not match this DEMO booking snapshot.', 'demo_payment_conflict');
        }
    }

    private function startAttempt(object $booking, ?object $intent, string $hash): array
    {
        $now = CarbonImmutable::now('UTC');
        $this->assertPayable($booking, $now);
        if (! $intent) {
            $intent = (object) [
                'guid' => (string) Str::uuid(), 'booking_guid' => $booking->guid, 'provider' => 'demo', 'environment' => 'demo',
                'amount' => (int) $booking->total_amount, 'currency' => $booking->currency, 'status' => 'requires_payment',
                'merchant_reference' => 'DEMO-'.Str::uuid(), 'expires_at' => $booking->payment_expires_at,
                'applied_attempt_guid' => null, 'verified_paid_at' => null, 'created_at' => now(), 'updated_at' => now(),
            ];
            DB::table('payment_intents')->insert((array) $intent);
        }
        $last = DB::table('payment_attempts')->where('payment_intent_guid', $intent->guid)->orderByDesc('attempt_number')->lockForUpdate()->first();
        if ($intent->applied_attempt_guid !== null || $intent->verified_paid_at !== null
            || ($last && ($last->verified_paid_at !== null || $last->reversed_at !== null))) {
            throw new BookingException('This DEMO intent already has a payment outcome.', 'demo_payment_conflict');
        }
        if ($intent->status === 'processing' && $last?->status === 'pending') {
            $this->assertAttempt($intent, $last);

            return $this->response($booking, $intent, $last);
        }
        if ($intent->status !== 'requires_payment' || ($last && ! in_array($last->status, ['failed', 'expired'], true))) {
            throw new BookingException('Resolve the current DEMO attempt before retrying.', 'demo_payment_conflict');
        }
        PaymentStates::assertIntentTransition($intent->status, 'processing');
        PaymentStates::assertAttemptTransition('initiating', 'pending');
        $attempt = (object) [
            'guid' => (string) Str::uuid(), 'payment_intent_guid' => $intent->guid, 'provider' => 'demo', 'environment' => 'demo',
            'attempt_number' => ($last?->attempt_number ?? 0) + 1, 'method' => 'demo', 'channel' => 'demo_simulator',
            'merchant_reference' => 'DEMO-'.Str::uuid(), 'provider_request_id' => (string) Str::uuid(),
            'request_hash' => $hash, 'amount' => (int) $booking->total_amount, 'currency' => $booking->currency,
            'status' => 'pending', 'presentation_type' => 'demo_simulator', 'instruction_expires_at' => $booking->payment_expires_at,
            'started_at' => $now, 'finished_at' => null, 'verified_paid_at' => null, 'reversed_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('payment_attempts')->insert((array) $attempt);
        DB::table('payment_intents')->where('guid', $intent->guid)->update(['status' => 'processing', 'updated_at' => now()]);
        $intent->status = 'processing';

        return $this->response($booking, $intent, $attempt);
    }

    private function assertAttempt(object $intent, object $attempt): void
    {
        if ($attempt->provider !== 'demo' || $attempt->environment !== 'demo' || $attempt->currency !== $intent->currency
            || (int) $attempt->amount !== (int) $intent->amount || $attempt->instruction_expires_at !== $intent->expires_at) {
            throw new BookingException('The payment attempt does not match this DEMO intent.', 'demo_payment_conflict');
        }
    }

    /** @param array{idempotency_key: string, attempt_guid: string, outcome: string} $input */
    private function applyOutcome(object $booking, User $actor, ?object $intent, array $input): array
    {
        $attempt = $intent ? DB::table('payment_attempts')->where('payment_intent_guid', $intent->guid)
            ->orderByDesc('attempt_number')->lockForUpdate()->first() : null;
        if (! $attempt || $attempt->guid !== $input['attempt_guid']) {
            throw new BookingException('The DEMO attempt is not the current booking attempt.', 'demo_payment_conflict');
        }
        $this->assertAttempt($intent, $attempt);
        $target = $input['outcome'] === 'success' ? 'paid' : $input['outcome'];
        if (in_array($attempt->status, ['paid', 'failed', 'expired'], true)) {
            if ($attempt->status !== $target
                || ($target === 'paid' && ($booking->status !== 'confirmed' || $this->eligibility->status($booking) !== 'paid'))
                || ($target !== 'paid' && ($intent->status !== 'requires_payment' || $intent->applied_attempt_guid !== null
                    || $intent->verified_paid_at !== null || $attempt->verified_paid_at !== null || $attempt->reversed_at !== null))) {
                throw new BookingException('This DEMO attempt already has a different outcome.', 'demo_payment_conflict');
            }

            return $this->response($booking, $intent, $attempt);
        }
        $now = CarbonImmutable::now('UTC');
        $this->assertPayable($booking, $now);
        if ($attempt->status !== 'pending' || $intent->status !== 'processing'
            || $intent->applied_attempt_guid !== null || $intent->verified_paid_at !== null
            || $attempt->verified_paid_at !== null || $attempt->reversed_at !== null) {
            throw new BookingException('The DEMO payment cannot apply this outcome.', 'demo_payment_conflict');
        }
        PaymentStates::assertAttemptTransition($attempt->status, $target);
        $intentStatus = $target === 'paid' ? 'paid' : 'requires_payment';
        PaymentStates::assertIntentTransition($intent->status, $intentStatus);
        DB::table('payment_attempts')->where('guid', $attempt->guid)->update([
            'status' => $target, 'finished_at' => $now, 'verified_paid_at' => $target === 'paid' ? $now : null, 'updated_at' => $now,
        ]);
        DB::table('payment_intents')->where('guid', $intent->guid)->update([
            'status' => $intentStatus, 'applied_attempt_guid' => $target === 'paid' ? $attempt->guid : null,
            'verified_paid_at' => $target === 'paid' ? $now : null, 'updated_at' => $now,
        ]);
        DB::table('payment_events')->insert([
            'guid' => (string) Str::uuid(), 'payment_intent_guid' => $intent->guid, 'payment_attempt_guid' => $attempt->guid,
            'provider' => 'demo', 'environment' => 'demo', 'provider_event_key' => 'demo:'.$attempt->guid.':'.$target,
            'payload_hash' => hash('sha256', $attempt->guid.':'.$target), 'verification' => 'verified',
            'event_type' => $target === 'paid' ? 'capture' : 'status', 'disposition' => 'applied',
            'amount' => (int) $attempt->amount, 'currency' => $attempt->currency,
            'provider_occurred_at' => $now, 'received_at' => $now,
            'metadata' => json_encode(['source' => 'demo_simulator', 'label' => 'DEMO', 'test_only' => true, 'actor_guid' => $actor->guid], JSON_THROW_ON_ERROR),
        ]);
        if ($target === 'paid') {
            DB::table('bookings')->where('guid', $booking->guid)->update(['status' => 'confirmed', 'updated_at' => $now]);
            DB::table('booking_status_histories')->insert([
                'guid' => (string) Str::uuid(), 'booking_guid' => $booking->guid, 'actor_guid' => $actor->guid,
                'old_status' => 'awaiting_payment', 'new_status' => 'confirmed', 'reason' => 'DEMO payment simulation; no real funds.', 'created_at' => $now,
            ]);
            $booking->status = 'confirmed';
            $this->notifications->record($booking->guid, $booking->user_guid, 'demo_paid');
        }
        $attempt->status = $target;
        $attempt->verified_paid_at = $target === 'paid' ? $now->toDateTimeString() : null;
        $intent->status = $intentStatus;
        $intent->applied_attempt_guid = $target === 'paid' ? $attempt->guid : null;
        $intent->verified_paid_at = $attempt->verified_paid_at;

        return $this->response($booking, $intent, $attempt);
    }

    private function response(object $booking, object $intent, object $attempt): array
    {
        return [
            'booking_guid' => $booking->guid, 'intent_guid' => $intent->guid, 'attempt_guid' => $attempt->guid,
            'provider' => 'demo', 'environment' => 'demo', 'test_only' => true, 'label' => 'DEMO',
            'status' => $attempt->status, 'booking_status' => $booking->status, 'payment_status' => $this->eligibility->status($booking),
            'amount' => (int) $booking->total_amount, 'currency' => $booking->currency,
            'expires_at' => CarbonImmutable::parse($booking->payment_expires_at, 'UTC')->toIso8601String(),
            'instruction' => ['presentation_type' => 'demo_simulator', 'label' => 'DEMO', 'test_only' => true],
        ];
    }
}
