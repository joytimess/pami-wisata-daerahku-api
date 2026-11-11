<?php

use App\Exceptions\BookingException;
use App\Services\BookingRefundService;
use App\Services\BookingService;
use App\Services\FinancialAnalyticsService;
use App\Services\PaymentEligibility;
use App\Services\PaymentReceiptService;
use App\Services\PaymentService;
use App\Services\PaymentSettlementService;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-11-08 08:00:00');
    config(['operations.demo_payments_enabled' => false, 'doku.environment' => 'production', 'doku.verified_channels' => []]);
    Http::preventStrayRequests();
});

/** Persisted verified ledger fixtures only; no provider request or Charge is activated. */
function historicalPayment(string $provider = 'doku', string $environment = 'sandbox'): array
{
    [$destination, $date] = seedDestination(capacity: 10, price: 10000, days: 5);
    $owner = makeUser();
    $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date, 2) + ['idempotency_key' => (string) Str::uuid()]);
    app(BookingService::class)->decide(makeUser(2), $booking->guid, true);
    $booking = app(TicketService::class)->booking($booking->guid);
    $intent = ['guid' => (string) Str::uuid(), 'booking_guid' => $booking->guid, 'provider' => $provider, 'environment' => $environment,
        'currency' => 'IDR', 'amount' => 20000, 'status' => 'paid', 'merchant_reference' => (string) Str::uuid(),
        'expires_at' => $booking->payment_expires_at, 'verified_paid_at' => now(), 'created_at' => now(), 'updated_at' => now()];
    DB::table('payment_intents')->insert($intent);
    $attempt = ['guid' => (string) Str::uuid(), 'payment_intent_guid' => $intent['guid'], 'provider' => $provider, 'environment' => $environment,
        'attempt_number' => 1, 'method' => $provider === 'demo' ? 'demo' : 'virtual_account', 'channel' => $provider === 'demo' ? 'demo_simulator' : 'bni',
        'merchant_reference' => (string) Str::uuid(), 'provider_request_id' => (string) Str::uuid(),
        'provider_reference' => $provider === 'demo' ? null : (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
        'amount' => 20000, 'currency' => 'IDR', 'status' => 'paid', 'started_at' => now(), 'verified_paid_at' => now(),
        'instruction_expires_at' => $booking->payment_expires_at, 'created_at' => now(), 'updated_at' => now()];
    DB::table('payment_attempts')->insert($attempt);
    DB::table('payment_intents')->where('guid', $intent['guid'])->update(['applied_attempt_guid' => $attempt['guid']]);
    DB::table('bookings')->where('guid', $booking->guid)->update(['status' => 'confirmed']);
    $event = historicalEvent($attempt);

    return [$owner, app(TicketService::class)->booking($booking->guid), $intent, $attempt, $event, $destination];
}

function historicalEvent(array $attempt, string $type = 'capture', array $changes = []): string
{
    $guid = (string) Str::uuid();
    DB::table('payment_events')->insert(array_replace(['guid' => $guid, 'payment_intent_guid' => $attempt['payment_intent_guid'],
        'payment_attempt_guid' => $attempt['guid'], 'provider' => $attempt['provider'], 'environment' => $attempt['environment'],
        'provider_event_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('b', 64), 'verification' => 'verified',
        'event_type' => $type, 'disposition' => 'applied', 'amount' => 20000, 'currency' => 'IDR',
        'provider_occurred_at' => now(), 'received_at' => now(), 'metadata' => json_encode(['source' => $attempt['provider'] === 'demo' ? 'demo_simulator' : 'historical_verified_ledger',
            // Real DEMO attempts have no provider reference and never carry a null snapshot key: absent keys are the legacy shape.
            'outcome' => array_filter(['status' => match ($type) {
                'capture' => 'paid', 'refund' => 'refunded', default => 'reversed'
            },
                'merchant_reference' => $attempt['merchant_reference'], 'provider_reference' => $attempt['provider_reference'],
                'amount' => 20000, 'currency' => 'IDR', 'occurred_at' => now()->toIso8601String(), 'method' => $attempt['method'], 'channel' => $attempt['channel']],
                fn (mixed $value): bool => $value !== null)], JSON_THROW_ON_ERROR)], $changes));

    return $guid;
}

it('keeps historical paid status receipt and original provenance after gateway configuration changes', function (string $provider, string $environment) {
    [$owner, $booking] = historicalPayment($provider, $environment);

    expect(app(PaymentEligibility::class)->status($booking))->toBe('paid');
    expect(app(PaymentEligibility::class)->provenance($booking))->toMatchArray(['payment_provider' => $provider, 'payment_environment' => $environment,
        'payment_test_only' => $environment !== 'production']);
    expect(app(PaymentReceiptService::class)->data($booking->guid))->toMatchArray(['state' => 'paid', 'environment' => $environment, 'test_only' => $environment !== 'production']);
    expect(app(PaymentService::class)->show($owner, $booking->guid))->toMatchArray(['provider' => $provider, 'environment' => $environment, 'payment_status' => 'paid', 'receipt_available' => true]);
    $this->assertDatabaseHas('payment_intents', ['booking_guid' => $booking->guid, 'provider' => $provider, 'environment' => $environment]);
    Http::assertNothingSent();
})->with([['doku', 'sandbox'], ['doku', 'production'], ['midtrans', 'sandbox'], ['midtrans', 'production'], ['demo', 'demo']]);

it('keeps sandbox and demo history readable but refuses production admission', function (string $provider, string $environment) {
    [, $booking] = historicalPayment($provider, $environment);
    app()->instance('env', 'production');

    expect(app(PaymentEligibility::class)->status($booking))->toBe('paid');
    expect(app(PaymentEligibility::class)->entitlement($booking))->toBe('unverified');
    expect(app(PaymentReceiptService::class)->data($booking->guid)['test_only'])->toBeTrue();
})->with([['doku', 'sandbox'], ['midtrans', 'sandbox'], ['demo', 'demo']]);

it('keeps a verified reversal readable only with its fully bound original capture', function (string $provider) {
    [, $booking, , $attempt, $capture] = historicalPayment($provider);
    historicalEvent($attempt, 'reversal');
    DB::table('payment_attempts')->where('guid', $attempt['guid'])->update(['status' => 'reversed', 'reversed_at' => now()]);
    DB::table('payment_intents')->where('booking_guid', $booking->guid)->update(['status' => 'needs_review']);

    expect(app(PaymentEligibility::class)->status($booking))->toBe('reversed');
    expect(app(PaymentReceiptService::class)->data($booking->guid)['state'])->toBe('adjusted');
    DB::table('payment_events')->where('guid', $capture)->update(['verification' => 'unverified']);
    expect(app(PaymentEligibility::class)->status($booking))->toBe('unverified');
    expect(app(PaymentReceiptService::class)->data($booking->guid))->toBeNull();
})->with(['doku', 'midtrans']);

it('rejects reversed evidence with a mismatched adjustment binding', function (array $changes) {
    [, $booking, , $attempt] = historicalPayment();
    config(['doku.environment' => 'sandbox']);
    historicalEvent($attempt, 'refund', $changes + ['metadata' => json_encode(['source' => 'doku_snap'])]);
    DB::table('payment_attempts')->where('guid', $attempt['guid'])->update(['status' => 'reversed', 'reversed_at' => now()]);
    DB::table('payment_intents')->where('booking_guid', $booking->guid)->update(['status' => 'needs_review']);

    expect(app(PaymentEligibility::class)->status($booking))->toBe('unverified');
})->with([[['amount' => 1]], [['currency' => 'USD']], [['provider_occurred_at' => '2026-11-07 08:00:00']], [['environment' => 'production']]]);

it('preserves manual refund status receipt and admission after a gateway switch', function () {
    [$owner, $booking, , , , $destination] = historicalPayment();
    config(['doku.environment' => 'sandbox']);
    DB::table('payment_events')->update(['metadata' => json_encode(['source' => 'doku_snap'])]);
    $admin = makeUser(1);
    assignTestDestination($admin, $destination);
    $service = app(BookingRefundService::class);
    $request = $service->request($owner, $booking->guid, ['idempotency_key' => 'historical-refund', 'reason' => 'Perubahan rencana perjalanan']);
    $service->decide($admin, $booking->guid, $request['guid'], ['decision' => 'approve', 'note' => 'Refund disetujui']);
    $service->settleManually(makeUser(2), $booking->guid, $request['guid'], ['settlement_method' => 'bank_transfer', 'settlement_reference' => 'HISTORY-REFUND-001',
        'settlement_amount' => 20000, 'settled_at' => now()->toIso8601String(), 'evidence_note' => 'Transfer penuh telah diverifikasi']);
    config(['doku.environment' => 'production']);

    expect(app(PaymentEligibility::class)->status($booking))->toBe('paid');
    expect(app(PaymentEligibility::class)->entitlement($booking))->toBe('refunded');
    expect(app(PaymentReceiptService::class)->data($booking->guid)['state'])->toBe('paid');
    expect($service->latestSummary($booking->guid)['status'])->toBe('manual_refunded');
});

it('aggregates real providers per environment once while retaining unknown financial fields and admin scope', function () {
    [, , , $doku, , $destination] = historicalPayment();
    historicalEvent($doku);
    historicalPayment('midtrans');
    historicalPayment('midtrans', 'production');
    historicalPayment('demo', 'demo');
    $admin = makeUser(1);
    assignTestDestination($admin, $destination);

    $finance = app(FinancialAnalyticsService::class);
    expect($finance->summary(makeUser(2), ['environment' => 'sandbox']))->toMatchArray(['provider' => 'mixed', 'gross_collected' => 40000,
        'gross_revenue' => 40000, 'successful_payment_attempts' => 2, 'refunds' => null, 'gateway_fees' => null, 'net_revenue' => null, 'refunds_complete' => false]);
    expect($finance->summary($admin, ['environment' => 'sandbox']))->toMatchArray(['provider' => 'doku', 'gross_collected' => 20000]);
    expect($finance->summary(makeUser(2), ['environment' => 'production']))->toMatchArray(['provider' => 'midtrans', 'gross_collected' => 20000]);
    expect($finance->summary(makeUser(2), ['environment' => 'demo']))->toMatchArray(['provider' => 'demo', 'gross_collected' => 20000]);
});

it('counts duplicate full refund evidence once and rejects unbound refund receipts', function () {
    [, , , $attempt] = historicalPayment();
    historicalEvent($attempt, 'refund');
    historicalEvent($attempt, 'refund');
    historicalEvent($attempt, 'refund', ['amount' => 1]);

    expect(app(FinancialAnalyticsService::class)->summary(makeUser(2), ['environment' => 'sandbox']))->toMatchArray(['gross_collected' => 20000, 'refunds' => 20000]);
});

it('assigns a duplicate full refund to its original receipt period before filtering the report', function () {
    [, , , $attempt] = historicalPayment();
    historicalEvent($attempt, 'refund');
    $this->travelTo('2026-11-09 08:00:00');
    historicalEvent($attempt, 'refund');
    $finance = app(FinancialAnalyticsService::class);
    $admin = makeUser(2);

    expect($finance->summary($admin, ['environment' => 'sandbox', 'from' => '2026-11-08', 'to' => '2026-11-08'])['refunds'])->toBe(20000);
    expect($finance->summary($admin, ['environment' => 'sandbox', 'from' => '2026-11-09', 'to' => '2026-11-09'])['refunds'])->toBeNull();
});

function historicalPendingPayment(string $provider = 'midtrans'): array
{
    $fixture = historicalPayment($provider);
    [, $booking, $intent, $attempt, $event] = $fixture;
    DB::table('bookings')->where('guid', $booking->guid)->update(['status' => 'awaiting_payment']);
    DB::table('payment_intents')->where('guid', $intent['guid'])->update(['status' => 'processing', 'applied_attempt_guid' => null, 'verified_paid_at' => null]);
    DB::table('payment_attempts')->where('guid', $attempt['guid'])->update(['status' => 'pending', 'verified_paid_at' => null]);
    DB::table('payment_events')->where('guid', $event)->update(['disposition' => 'recorded']);

    return $fixture;
}

it('applies a bound capture once without any provider transport or active channel configuration', function (string $provider) {
    [, $booking, $intent, $attempt, $event] = historicalPendingPayment($provider);
    $settlements = app(PaymentSettlementService::class);

    $settlements->apply($event);
    $settlements->apply($event);
    $duplicate = historicalEvent($attempt, changes: ['disposition' => 'recorded']);
    $settlements->apply($duplicate);

    $this->assertDatabaseHas('payment_intents', ['guid' => $intent['guid'], 'provider' => $provider, 'environment' => 'sandbox', 'status' => 'paid', 'applied_attempt_guid' => $attempt['guid']]);
    $this->assertDatabaseHas('payment_events', ['guid' => $duplicate, 'disposition' => 'duplicate_capture']);
    expect(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'confirmed')->count())->toBe(1);
    expect(DB::table('notifications')->where('booking_guid', $booking->guid)->where('type', 'booking.payment_receipt')->count())->toBe(1);
    Http::assertNothingSent();
})->with(['doku', 'midtrans']);

it('quarantines a capture whose normalized snapshot disagrees with its original binding', function (array $change) {
    [, $booking, , , $event] = historicalPendingPayment();
    $metadata = json_decode(DB::table('payment_events')->where('guid', $event)->value('metadata'), true);
    $metadata['outcome'] = array_replace($metadata['outcome'], $change);
    DB::table('payment_events')->where('guid', $event)->update(['metadata' => json_encode($metadata)]);

    app(PaymentSettlementService::class)->apply($event);

    $this->assertDatabaseHas('payment_events', ['guid' => $event, 'disposition' => 'quarantined']);
    $this->assertDatabaseHas('payment_intents', ['booking_guid' => $booking->guid, 'status' => 'needs_review', 'applied_attempt_guid' => null]);
    expect(DB::table('notifications')->where('booking_guid', $booking->guid)->where('type', 'booking.payment_receipt')->count())->toBe(0);
})->with([[['status' => 'refunded']], [['method' => 'qris']], [['channel' => 'bca']], [['amount' => 1]], [['currency' => 'USD']],
    [['merchant_reference' => 'another-order']], [['provider_reference' => 'another-transaction']], [['occurred_at' => '2026-11-08T07:59:00+00:00']],
    [['provider' => 'doku']], [['environment' => 'production']],
    'null provider' => [['provider' => null]], 'null environment' => [['environment' => null]],
    'null method' => [['method' => null]], 'null channel' => [['channel' => null]],
    'null merchant reference' => [['merchant_reference' => null]], 'null provider reference' => [['provider_reference' => null]],
    'null amount' => [['amount' => null]], 'null currency' => [['currency' => null]], 'null time' => [['occurred_at' => null]]]);

it('preserves the original adjustment and admission after duplicate adjustment and out of order paid evidence', function () {
    [, $booking, , $attempt] = historicalPayment('midtrans');
    $refund = historicalEvent($attempt, 'refund', ['disposition' => 'recorded']);
    $settlements = app(PaymentSettlementService::class);
    $settlements->apply($refund);
    $original = DB::table('payment_attempts')->where('guid', $attempt['guid'])->value('reversed_at');
    $auditCount = DB::table('booking_refund_request_events')->count();
    $this->travelTo('2026-11-08 08:01:00');
    $duplicate = historicalEvent($attempt, 'refund', ['disposition' => 'recorded']);
    $settlements->apply($duplicate);
    $latePaid = historicalEvent($attempt, changes: ['disposition' => 'recorded']);
    $settlements->apply($latePaid);

    $this->assertDatabaseHas('payment_attempts', ['guid' => $attempt['guid'], 'status' => 'reversed', 'reversed_at' => $original]);
    $this->assertDatabaseHas('payment_events', ['guid' => $duplicate, 'disposition' => 'duplicate_adjustment']);
    $this->assertDatabaseHas('payment_events', ['guid' => $latePaid, 'disposition' => 'duplicate_capture']);
    expect(DB::table('booking_refund_request_events')->count())->toBe($auditCount);
    expect(app(PaymentEligibility::class)->entitlement($booking))->toBe('reversed');
});

it('records late verified funds as unapplied without publishing paid entitlement', function () {
    [, $booking, $intent, $attempt, $event] = historicalPendingPayment();
    $this->travelTo('2026-11-08 10:00:00');
    DB::table('payment_events')->where('guid', $event)->update(['disposition' => 'ignored']);
    $late = historicalEvent($attempt, changes: ['disposition' => 'recorded']);

    app(PaymentSettlementService::class)->apply($late);

    $this->assertDatabaseHas('payment_intents', ['guid' => $intent['guid'], 'status' => 'needs_review', 'applied_attempt_guid' => null]);
    $this->assertDatabaseHas('payment_attempts', ['guid' => $attempt['guid'], 'status' => 'paid']);
    $this->assertDatabaseHas('payment_events', ['guid' => $late, 'disposition' => 'unapplied']);
    expect(app(PaymentEligibility::class)->entitlement($booking))->toBe('unverified');
    expect(app(FinancialAnalyticsService::class)->summary(makeUser(2), ['environment' => 'sandbox']))->toMatchArray(['gross_collected' => 20000, 'gross_revenue' => 0, 'unapplied_collected' => 20000]);
});

it('blocks production refund mutations for historical sandbox evidence while leaving history readable', function (string $stage) {
    [$owner, $booking] = historicalPayment();
    $service = app(BookingRefundService::class);
    $admin = makeUser(2);
    if ($stage !== 'request') {
        $request = $service->request($owner, $booking->guid, ['idempotency_key' => 'sandbox-refund', 'reason' => 'Perubahan rencana perjalanan']);
        if ($stage === 'settle') {
            $service->decide($admin, $booking->guid, $request['guid'], ['decision' => 'approve']);
        }
    }
    app()->instance('env', 'production');

    if ($stage === 'request') {
        expect(fn () => $service->request($owner, $booking->guid, ['idempotency_key' => 'sandbox-refund', 'reason' => 'Perubahan rencana perjalanan']))
            ->toThrow(BookingException::class);
        $this->assertDatabaseCount('booking_refund_requests', 0);
    } elseif ($stage === 'decision') {
        expect($service->decide($admin, $booking->guid, $request['guid'], ['decision' => 'approve'])['status'])->toBe('needs_review');
    } else {
        expect(fn () => $service->settleManually($admin, $booking->guid, $request['guid'], ['settlement_method' => 'bank_transfer',
            'settlement_reference' => 'SANDBOX-MUST-NOT-SETTLE', 'settlement_amount' => 20000, 'settled_at' => now()->toIso8601String(),
            'evidence_note' => 'Tidak boleh menyelesaikan dana Sandbox']))->toThrow(BookingException::class);
        $this->assertDatabaseHas('booking_refund_requests', ['guid' => $request['guid'], 'status' => 'manual_action_required', 'settlement_reference' => null]);
    }
    expect(app(PaymentEligibility::class)->status($booking))->toBe('paid');
    expect(app(PaymentReceiptService::class)->data($booking->guid)['test_only'])->toBeTrue();
})->with(['request', 'decision', 'settle']);

it('preserves the existing full manual refund workflow for valid production evidence', function () {
    [$owner, $booking] = historicalPayment(environment: 'production');
    app()->instance('env', 'production');
    $service = app(BookingRefundService::class);
    $admin = makeUser(2);

    $request = $service->request($owner, $booking->guid, ['idempotency_key' => 'production-refund', 'reason' => 'Perubahan rencana perjalanan']);
    $service->decide($admin, $booking->guid, $request['guid'], ['decision' => 'approve']);
    $result = $service->settleManually($admin, $booking->guid, $request['guid'], ['settlement_method' => 'bank_transfer',
        'settlement_reference' => 'VERIFIED-PRODUCTION-REFUND', 'settlement_amount' => 20000, 'settled_at' => now()->toIso8601String(),
        'evidence_note' => 'Transfer penuh telah diverifikasi']);

    expect($result['status'])->toBe('manual_refunded');
    expect(app(PaymentEligibility::class)->entitlement($booking))->toBe('refunded');
    expect(app(PaymentReceiptService::class)->data($booking->guid)['environment'])->toBe('production');
});

it('takes the adjusted receipt date from the bound reversal rather than a later unrelated event', function () {
    [, $booking, , $attempt] = historicalPayment();
    historicalEvent($attempt, 'reversal');
    DB::table('payment_attempts')->where('guid', $attempt['guid'])->update(['status' => 'reversed', 'reversed_at' => now()]);
    DB::table('payment_intents')->where('booking_guid', $booking->guid)->update(['status' => 'needs_review']);
    historicalEvent($attempt, 'reversal', ['environment' => 'production', 'provider_occurred_at' => '2026-11-09 08:00:00']);

    expect(app(PaymentReceiptService::class)->data($booking->guid)['adjusted_at'])->toBe('2026-11-08 15:00');
});

it('rejects historical capture snapshot contradictions for status production admission receipts and finance', function (array $change) {
    [, $booking, , , $event] = historicalPayment(environment: 'production');
    app()->instance('env', 'production');
    $metadata = json_decode(DB::table('payment_events')->where('guid', $event)->value('metadata'), true);
    $metadata['outcome'] = array_replace($metadata['outcome'], $change);
    DB::table('payment_events')->where('guid', $event)->update(['metadata' => json_encode($metadata)]);

    expect(app(PaymentEligibility::class)->status($booking))->toBe('unverified');
    expect(app(PaymentEligibility::class)->entitlement($booking))->toBe('unverified');
    expect(app(PaymentReceiptService::class)->data($booking->guid))->toBeNull();
    expect(app(FinancialAnalyticsService::class)->summary(makeUser(2), ['environment' => 'production']))
        ->toMatchArray(['availability' => 'unavailable', 'gross_collected' => null, 'gross_revenue' => null]);
})->with([
    'provider mismatch' => [['provider' => 'midtrans']],
    'environment mismatch' => [['environment' => 'sandbox']],
    'instant mismatch' => [['occurred_at' => '2026-11-08T08:01:00+00:00']],
    'fractional instant mismatch' => [['occurred_at' => '2026-11-08T08:00:00.000001+00:00']],
    'malformed time' => [['occurred_at' => 'not-a-timestamp']],
    'trailing garbage' => [['occurred_at' => '2026-11-08T08:00:00+00:00garbage']],
    'null time' => [['occurred_at' => null]],
    'null provider' => [['provider' => null]],
    'null environment' => [['environment' => null]],
    'null method' => [['method' => null]],
    'null channel' => [['channel' => null]],
    'null merchant reference' => [['merchant_reference' => null]],
    'null provider reference' => [['provider_reference' => null]],
    'null amount' => [['amount' => null]],
    'null currency' => [['currency' => null]],
]);

it('accepts equivalent historical timestamp instants and absent legacy snapshot fields', function (?string $time) {
    [, $booking, , , $event] = historicalPayment(environment: 'production');
    app()->instance('env', 'production');
    $metadata = json_decode(DB::table('payment_events')->where('guid', $event)->value('metadata'), true);
    if ($time === null) {
        unset($metadata['outcome']['provider'], $metadata['outcome']['environment'], $metadata['outcome']['occurred_at']);
    } else {
        $metadata['outcome'] = array_replace($metadata['outcome'], ['provider' => 'doku', 'environment' => 'production', 'occurred_at' => $time]);
    }
    DB::table('payment_events')->where('guid', $event)->update(['metadata' => json_encode($metadata)]);

    expect(app(PaymentEligibility::class)->status($booking))->toBe('paid');
    expect(app(PaymentEligibility::class)->entitlement($booking))->toBe('paid');
    expect(app(PaymentReceiptService::class)->data($booking->guid))->toMatchArray(['state' => 'paid', 'environment' => 'production']);
    expect(app(FinancialAnalyticsService::class)->summary(makeUser(2), ['environment' => 'production']))
        ->toMatchArray(['gross_collected' => 20000, 'gross_revenue' => 20000]);
})->with(['2026-11-08T15:00:00+07:00', '2026-11-08T03:00:00-05:00', '2026-11-08T08:00:00Z', '2026-11-08T08:00:00.000000+00:00', null]);

it('rejects contradictory historical adjustment snapshots without dropping the original collected funds', function (array $change) {
    [, $booking, , $attempt] = historicalPayment(environment: 'production');
    $refund = historicalEvent($attempt, 'refund');
    DB::table('payment_attempts')->where('guid', $attempt['guid'])->update(['status' => 'reversed', 'reversed_at' => now()]);
    DB::table('payment_intents')->where('booking_guid', $booking->guid)->update(['status' => 'needs_review']);
    $metadata = json_decode(DB::table('payment_events')->where('guid', $refund)->value('metadata'), true);
    $metadata['outcome'] = array_replace($metadata['outcome'], $change);
    DB::table('payment_events')->where('guid', $refund)->update(['metadata' => json_encode($metadata)]);

    expect(app(PaymentEligibility::class)->status($booking))->toBe('unverified');
    expect(app(PaymentReceiptService::class)->data($booking->guid))->toBeNull();
    expect(app(FinancialAnalyticsService::class)->summary(makeUser(2), ['environment' => 'production']))
        ->toMatchArray(['gross_collected' => 20000, 'refunds' => null]);
})->with([[['provider' => 'midtrans']], [['environment' => 'sandbox']], [['occurred_at' => '2026-11-08T08:01:00+00:00']], [['occurred_at' => 'not-a-timestamp']]]);
