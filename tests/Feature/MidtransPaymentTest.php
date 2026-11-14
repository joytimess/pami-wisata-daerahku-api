<?php

use App\Services\BookingService;
use App\Services\Midtrans\MidtransClient;
use App\Services\PaymentSettlementService;
use App\Services\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Midtrans reservation, idempotency and Charge through the public API. The provider is always Http::fake; no real
 * transaction is ever created. DOKU stays untouched and inactive while Midtrans is enabled.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 08:00:00', 'UTC'));
    config(['operations.demo_payments_enabled' => false, 'midtrans' => [
        'enabled' => true, 'environment' => 'sandbox', 'server_key' => 'SB-Mid-server-FIXTURE0000000000', 'merchant_id' => 'G000000001',
        'channels' => ['va_bca', 'va_bni', 'qris'], 'verified_channels' => [], 'production_approved' => false,
        'base_urls' => ['sandbox' => 'https://api.sandbox.midtrans.com', 'production' => 'https://api.midtrans.com'],
        'http_timeout_seconds' => 10, 'minimum_expiry_seconds' => 30, 'qris_acquirer' => 'gopay',
    ]]);
    Http::preventStrayRequests();
});

function midtransPayable(): array
{
    [$destination, $date] = seedDestination(capacity: 5, price: 10000, days: 5);
    $owner = makeUser();
    $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date, 2) + ['idempotency_key' => (string) Str::uuid()]);
    $booking = app(BookingService::class)->decide(makeUser(2), $booking->guid, true);
    startPaymentCountdown($booking->guid);

    return [$owner, app(TicketService::class)->booking($booking->guid)];
}

/** Echoes the order the PAMI request asked for, the way the provider would. */
function midtransEcho(?string $transactionId = 'trx-0001', ?Closure $before = null, array $override = []): Closure
{
    return function (ClientRequest $request) use ($transactionId, $before, $override) {
        $before?->__invoke($request);
        $order = $request['transaction_details']['order_id'];
        $transactionId ??= 'trx-'.$order;
        $expiry = CarbonImmutable::parse($request['custom_expiry']['order_time'])->addMinutes($request['custom_expiry']['expiry_duration'])
            ->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s');
        $common = ['status_code' => '201', 'transaction_id' => $transactionId, 'order_id' => $order, 'merchant_id' => 'G000000001',
            'gross_amount' => number_format($request['transaction_details']['gross_amount'], 2, '.', ''), 'currency' => 'IDR',
            'transaction_time' => CarbonImmutable::parse($request['custom_expiry']['order_time'])->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s'),
            'transaction_status' => 'pending', 'fraud_status' => 'accept', 'expiry_time' => $expiry];

        return Http::response(array_replace($request['payment_type'] === 'qris'
            ? $common + ['payment_type' => 'qris', 'acquirer' => 'gopay', 'qr_string' => '00020101FIXTURE',
                'actions' => [['name' => 'generate-qr-code', 'method' => 'GET', 'url' => 'https://api.sandbox.midtrans.com/v2/qris/'.$transactionId.'/qr-code']]]
            : $common + ['payment_type' => 'bank_transfer', 'va_numbers' => [['bank' => $request['bank_transfer']['bank'], 'va_number' => '81234500001']]], $override));
    };
}

function midtransStart(object $owner, object $booking, string $key = 'start', string $method = 'virtual_account', string $channel = 'bca')
{
    return test()->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts', ['method_code' => $method, 'channel' => $channel],
        bearer($owner) + ['Idempotency-Key' => $key]);
}

it('advertises exactly BCA, BNI and QRIS as native channels and never DOKU', function () {
    [$owner, $booking] = midtransPayable();

    $response = $this->getJson('/api/v1/payments/methods?booking_guid='.$booking->guid, bearer($owner))->assertOk();

    $response->assertJsonPath('data.provider', 'midtrans')->assertJsonPath('data.environment', 'sandbox')->assertJsonPath('data.test_only', true)
        ->assertJsonPath('data.can_pay', true);
    $methods = collect($response->json('data.methods'))->keyBy('code');
    expect($methods->keys()->all())->toBe(['qris', 'virtual_account'])
        ->and($methods['virtual_account']['presentation_type'])->toBe('virtual_account')
        ->and(collect($methods['virtual_account']['channels'])->pluck('channel')->all())->toBe(['bca', 'bni'])
        ->and($methods['qris']['presentation_type'])->toBe('qr_image')
        ->and(collect($methods['qris']['channels'])->pluck('channel')->all())->toBe(['qris'])
        ->and(json_encode($response->json()))->not->toContain('SB-Mid-server');
});

it('reports unavailable with a stable reason when Midtrans is disabled or misconfigured, and does not fall back to DOKU', function (array $override, string $reason) {
    config(['midtrans' => array_replace(config('midtrans'), $override)]);
    [$owner, $booking] = midtransPayable();

    $this->getJson('/api/v1/payments/methods?booking_guid='.$booking->guid, bearer($owner))->assertOk()
        ->assertJsonPath('data.provider', 'midtrans')->assertJsonPath('data.can_pay', false)->assertJsonPath('data.reason', $reason);
    midtransStart($owner, $booking)->assertConflict()->assertJsonPath('code', $reason);
    $this->assertDatabaseCount('payment_attempts', 0);
})->with([
    'missing key' => [['server_key' => ''], 'midtrans_not_configured'],
    'no enabled channel' => [['channels' => []], 'channel_not_enabled'],
]);

it('stays Midtrans and fails closed while Midtrans is disabled, never DOKU', function () {
    config(['midtrans.enabled' => false]);
    [$owner, $booking] = midtransPayable();

    $this->getJson('/api/v1/payments/methods?booking_guid='.$booking->guid, bearer($owner))->assertOk()
        ->assertJsonPath('data.provider', 'midtrans')->assertJsonPath('data.can_pay', false)->assertJsonPath('data.reason', 'midtrans_disabled');
    midtransStart($owner, $booking)->assertConflict()->assertJsonPath('code', 'midtrans_disabled');
    $this->assertDatabaseCount('payment_attempts', 0);
});

it('protects creation with authentication ownership and server authority', function () {
    [$owner, $booking] = midtransPayable();
    $path = '/api/v1/bookings/'.$booking->guid.'/payment-attempts';
    Http::fake();

    $this->postJson($path)->assertUnauthorized();
    $this->postJson($path, [], bearer(makeUser()))->assertNotFound();
    $this->postJson($path, [], bearer(makeUser(1)))->assertNotFound();
    $this->postJson($path, [], bearer($owner))->assertUnprocessable();
    $this->postJson($path, ['method_code' => 'ewallet', 'channel' => 'dana'], bearer($owner) + ['Idempotency-Key' => 'k'])->assertUnprocessable()->assertJsonValidationErrors(['method_code']);
    $this->postJson($path, ['method_code' => 'virtual_account', 'channel' => 'permata'], bearer($owner) + ['Idempotency-Key' => 'k'])->assertUnprocessable()->assertJsonValidationErrors(['channel']);
    foreach (['amount', 'currency', 'provider', 'environment', 'status', 'paid_at', 'merchant_id', 'expiry', 'fee', 'total_amount', 'invoice_id', 'booking_guid', 'paid', 'order_id', 'custom_expiry'] as $field) {
        $this->postJson($path, ['method_code' => 'virtual_account', 'channel' => 'bca', $field => 'client-override'], bearer($owner) + ['Idempotency-Key' => 'k'])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    Http::assertNothingSent();
    $this->assertDatabaseCount('payment_attempts', 0);
    $this->assertDatabaseCount('payment_intents', 0);
});

it('creates one BCA attempt: durable reservation, one Charge, absolute deadline and a native VA instruction', function () {
    [$owner, $booking] = midtransPayable();
    $reserved = null;
    Http::fake(['*' => midtransEcho('trx-bca', function (ClientRequest $request) use (&$reserved) {
        // The reservation must already be committed when the provider is called.
        $reserved = DB::table('payment_attempts')->where('merchant_reference', $request['transaction_details']['order_id'])->first();
    })]);

    $response = midtransStart($owner, $booking)->assertOk();

    $data = $response->json('data');
    expect($reserved)->not->toBeNull()->and($reserved->status)->toBe('initiating')
        ->and($data)->toMatchArray(['provider' => 'midtrans', 'environment' => 'sandbox', 'test_only' => true, 'status' => 'pending',
            'method_code' => 'virtual_account', 'channel' => 'bca', 'amount' => 20000, 'currency' => 'IDR', 'booking_status' => 'awaiting_payment',
            'payment_status' => 'unverified', 'server_time' => '2026-11-08T08:00:00+00:00', 'expires_at' => '2026-11-08T08:15:00+00:00'])
        ->and($data['instruction'])->toBe(['presentation_type' => 'virtual_account', 'bank_code' => 'bca', 'va_number' => '81234500001', 'copyable' => true]);
    $attempt = DB::table('payment_attempts')->first();
    $intent = DB::table('payment_intents')->first();
    expect($attempt->provider)->toBe('midtrans')->and($attempt->environment)->toBe('sandbox')
        ->and($attempt->merchant_reference)->toBe('PAMI-'.$attempt->guid)->and($attempt->provider_reference)->toBe('trx-bca')
        ->and($attempt->status)->toBe('pending')->and($attempt->presentation_type)->toBe('virtual_account')
        ->and($attempt->instruction_expires_at)->toBe('2026-11-08 08:15:00')->and((int) $attempt->amount)->toBe(20000)
        ->and($intent->provider)->toBe('midtrans')->and($intent->environment)->toBe('sandbox')->and($intent->status)->toBe('processing')
        ->and($attempt->instruction_ciphertext)->not->toContain('81234500001');
    Http::assertSentCount(1);
    Http::assertSent(fn (ClientRequest $request) => $request['custom_expiry'] === ['order_time' => '2026-11-08 15:00:00 +0700', 'expiry_duration' => 15, 'unit' => 'minute']
        && $request['transaction_details']['gross_amount'] === 20000);
});

it('creates BNI and QRIS attempts with channel-specific native instructions and never exposes the provider image URL', function () {
    Http::fake(['*' => midtransEcho(null)]);
    [$owner, $booking] = midtransPayable();
    midtransStart($owner, $booking, 'bni', 'virtual_account', 'bni')->assertOk()->assertJsonPath('data.instruction.bank_code', 'bni');

    [$owner, $booking] = midtransPayable();
    $response = midtransStart($owner, $booking, 'qris', 'qris', 'qris')->assertOk();

    expect($response->json('data.instruction'))->toBe(['presentation_type' => 'qr_image', 'acquirer' => 'gopay', 'image_available' => true, 'image_content_type' => 'image/png'])
        ->and(json_encode($response->json()))->not->toContain('api.sandbox.midtrans.com')->not->toContain('00020101FIXTURE');
});

it('returns the same attempt for a same-key retry and never sends a second Charge', function () {
    [$owner, $booking] = midtransPayable();
    Http::fake(['*' => midtransEcho()]);

    $first = midtransStart($owner, $booking, 'double-tap')->assertOk()->json('data');
    $second = midtransStart($owner, $booking, 'double-tap')->assertOk()->json('data');
    $third = midtransStart($owner, $booking, 'other-key')->assertOk()->json('data'); // Pending attempt: still the same one.

    expect($second['attempt_guid'])->toBe($first['attempt_guid'])->and($third['attempt_guid'])->toBe($first['attempt_guid']);
    Http::assertSentCount(1);
    $this->assertDatabaseCount('payment_attempts', 1);
});

it('rejects the same key reused for a different request', function () {
    [$owner, $booking] = midtransPayable();
    Http::fake(['*' => midtransEcho()]);
    midtransStart($owner, $booking, 'reused')->assertOk();

    midtransStart($owner, $booking, 'reused', 'virtual_account', 'bni')->assertConflict()->assertJsonPath('code', 'idempotency_conflict');

    Http::assertSentCount(1);
});

it('keeps a timed-out Charge as unknown on the same order and a retry never creates a second order', function () {
    [$owner, $booking] = midtransPayable();
    $calls = 0;
    Http::fake(['*' => function () use (&$calls) {
        $calls++;

        throw new ConnectionException('cURL error 28');
    }]);

    $first = midtransStart($owner, $booking, 'timeout')->assertOk()->json('data');
    $retry = midtransStart($owner, $booking, 'timeout')->assertOk()->json('data');
    $newKey = midtransStart($owner, $booking, 'timeout-2')->assertOk()->json('data');

    expect($first)->toMatchArray(['status' => 'unknown', 'reason' => 'provider_unavailable'])->and($first['instruction'])->toBe(['presentation_type' => 'unavailable'])
        ->and($retry['attempt_guid'])->toBe($first['attempt_guid'])->and($newKey['attempt_guid'])->toBe($first['attempt_guid'])->and($calls)->toBe(1);
    $this->assertDatabaseCount('payment_attempts', 1);
    expect(DB::table('payment_attempts')->value('status'))->toBe('unknown');
});

it('keeps the committed reservation when the process dies after the provider accepted the Charge, and never orders twice', function () {
    [$owner, $booking] = midtransPayable();
    $calls = 0;
    Http::fake(['*' => function (ClientRequest $request) use (&$calls) {
        $calls++;

        throw new RuntimeException('process died after the provider accepted the Charge');
    }]);

    $first = midtransStart($owner, $booking, 'crash')->assertOk()->json('data');

    expect($first['status'])->toBe('unknown');
    $attempt = DB::table('payment_attempts')->first();
    expect($attempt->status)->toBe('unknown')->and($attempt->merchant_reference)->toBe('PAMI-'.$attempt->guid);
    Http::fake(['*' => midtransEcho()]);
    $retry = midtransStart($owner, $booking, 'crash')->assertOk()->json('data');
    expect($retry['attempt_guid'])->toBe($first['attempt_guid'])->and($calls)->toBe(1);
    $this->assertDatabaseCount('payment_attempts', 1);
});

it('records a definitive provider rejection as failed and keeps the booking payable without a hidden retry', function () {
    [$owner, $booking] = midtransPayable();
    Http::fake(['*' => Http::response(['status_code' => '402', 'status_message' => 'Payment channel is not activated.'])]);

    $data = midtransStart($owner, $booking)->assertOk()->json('data');

    expect($data)->toMatchArray(['status' => 'failed', 'reason' => 'provider_rejected'])->and($data['instruction'])->toBe(['presentation_type' => 'unavailable']);
    Http::assertSentCount(1);
    expect(DB::table('payment_intents')->value('status'))->toBe('requires_payment')->and(DB::table('bookings')->value('status'))->toBe('awaiting_payment');
});

it('keeps an ambiguous HTTP answer to a Charge (408, 409, redirect) unknown instead of proving the order unpaid', function (int $httpStatus) {
    [$owner, $booking] = midtransPayable();
    Http::fake(['*' => Http::response(['status_message' => 'ambiguous'], $httpStatus)]);

    $data = midtransStart($owner, $booking)->assertOk()->json('data');

    expect($data['status'])->not->toBe('failed')
        ->and(DB::table('payment_attempts')->value('status'))->toBeIn(['unknown', 'initiating'])
        ->and(DB::table('bookings')->value('status'))->toBe('awaiting_payment');
})->with([408, 409, 302]);

it('never shows an instruction whose provider expiry exceeds the booking deadline', function () {
    [$owner, $booking] = midtransPayable();
    Http::fake(['*' => midtransEcho('trx-late', null, ['expiry_time' => '2026-11-08 16:30:00'])]);

    $data = midtransStart($owner, $booking)->assertOk()->json('data');

    expect($data['status'])->toBe('unknown')->and($data['instruction'])->toBe(['presentation_type' => 'unavailable']);
    expect(DB::table('payment_attempts')->value('instruction_ciphertext'))->toBeNull();
});

it('refuses to create inside the minimum window or after the deadline without calling the provider', function () {
    [$owner, $booking] = midtransPayable();
    Http::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-08 08:14:45', 'UTC'));

    midtransStart($owner, $booking)->assertConflict()->assertJsonPath('code', 'payment_window_closed');
    $this->travelTo(CarbonImmutable::parse('2026-11-08 08:15:00', 'UTC'));
    midtransStart($owner, $booking, 'late')->assertConflict()->assertJsonPath('code', 'payment_window_closed');

    Http::assertNothingSent();
    $this->assertDatabaseCount('payment_attempts', 0);
});

it('refuses a booking that already has a DOKU or DEMO intent', function (string $provider, string $environment) {
    [$owner, $booking] = midtransPayable();
    DB::table('payment_intents')->insert(['guid' => (string) Str::uuid(), 'booking_guid' => $booking->guid, 'provider' => $provider, 'environment' => $environment,
        'amount' => 20000, 'currency' => 'IDR', 'status' => 'requires_payment', 'merchant_reference' => (string) Str::uuid(),
        'expires_at' => $booking->payment_expires_at, 'created_at' => now(), 'updated_at' => now()]);
    Http::fake();

    midtransStart($owner, $booking)->assertConflict()->assertJsonPath('code', 'payment_provider_conflict');

    Http::assertNothingSent();
    $this->assertDatabaseCount('payment_attempts', 0);
})->with([['doku', 'sandbox'], ['doku', 'production'], ['demo', 'demo']]);

it('refuses unpayable bookings: free, unapproved and foreign', function () {
    [$destination, $date] = seedDestination(capacity: 5, price: 0, days: 5);
    $owner = makeUser();
    $free = app(BookingService::class)->create($owner, bookingPayload($destination, $date, 1) + ['idempotency_key' => (string) Str::uuid()]);
    $free = app(BookingService::class)->decide(makeUser(2), $free->guid, true);
    [$paidOwner, $paid] = midtransPayable();
    DB::table('bookings')->where('guid', $paid->guid)->update(['status' => 'pending_approval']);
    Http::fake();

    midtransStart($owner, $free, 'free')->assertConflict();
    midtransStart($paidOwner, $paid, 'unapproved')->assertConflict()->assertJsonPath('code', 'booking_not_awaiting_payment');

    Http::assertNothingSent();
    $this->assertDatabaseCount('payment_attempts', 0);
});

it('never demotes a payment applied before the Charge response is stored', function () {
    [$owner, $booking] = midtransPayable();
    Http::fake(['*' => midtransEcho('trx-race', function (ClientRequest $request) {
        // The verified settlement arrives while the Charge response is still in flight.
        $attempt = DB::table('payment_attempts')->where('merchant_reference', $request['transaction_details']['order_id'])->first();
        $eventGuid = (string) Str::uuid();
        $outcome = ['status' => 'paid', 'merchant_reference' => $attempt->merchant_reference, 'provider_reference' => 'trx-race', 'amount' => 20000,
            'currency' => 'IDR', 'occurred_at' => now()->toIso8601String(), 'method' => 'virtual_account', 'channel' => 'bca'];
        DB::table('payment_events')->insert(['guid' => $eventGuid, 'payment_intent_guid' => $attempt->payment_intent_guid, 'payment_attempt_guid' => $attempt->guid,
            'provider' => 'midtrans', 'environment' => 'sandbox', 'provider_event_key' => 'settlement-race', 'payload_hash' => hash('sha256', 'settlement-race'),
            'verification' => 'verified', 'event_type' => 'capture', 'disposition' => 'recorded', 'amount' => 20000, 'currency' => 'IDR',
            'provider_occurred_at' => now(), 'received_at' => now(), 'metadata' => json_encode(['source' => 'midtrans_status', 'outcome' => $outcome])]);
        app(PaymentSettlementService::class)->apply($eventGuid);
    })]);

    $data = midtransStart($owner, $booking, 'race')->assertOk()->json('data');

    $attempt = DB::table('payment_attempts')->first();
    expect($attempt->status)->toBe('paid')->and($data['status'])->toBe('paid')->and($data['instruction'])->toBe(['presentation_type' => 'unavailable'])
        ->and(DB::table('bookings')->value('status'))->toBe('confirmed')->and(DB::table('payment_intents')->value('status'))->toBe('paid')
        ->and($attempt->provider_reference)->toBe('trx-race');
    $this->assertDatabaseCount('payment_attempts', 1);
});

it('exposes the Midtrans instruction in the payment snapshot only while it is payable', function () {
    [$owner, $booking] = midtransPayable();
    Http::fake(['*' => midtransEcho()]);
    midtransStart($owner, $booking)->assertOk();

    $this->getJson('/api/v1/bookings/'.$booking->guid.'/payment-intent', bearer($owner))->assertOk()
        ->assertJsonPath('data.provider', 'midtrans')->assertJsonPath('data.instruction.va_number', '81234500001')
        ->assertJsonPath('data.last_attempt.channel', 'bca')->assertJsonPath('data.can_create', false);
    $this->travelTo(CarbonImmutable::parse('2026-11-08 08:15:00', 'UTC'));
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/payment-intent', bearer($owner))->assertOk()
        ->assertJsonPath('data.instruction.presentation_type', 'unavailable')->assertJsonPath('data.can_create', false);
});

it('does not touch DOKU transport or configuration while creating a Midtrans attempt', function () {
    [$owner, $booking] = midtransPayable();
    Http::fake(['*' => midtransEcho()]);

    midtransStart($owner, $booking)->assertOk();

    Http::assertSent(fn (ClientRequest $request) => str_starts_with($request->url(), 'https://api.sandbox.midtrans.com/'));
    Http::assertNotSent(fn (ClientRequest $request) => str_contains($request->url(), 'doku'));
    expect(app(MidtransClient::class)->configured())->toBeTrue();
});

it('binds the configured merchant identity to the attempt at reservation', function () {
    [$owner, $booking] = midtransPayable();
    Http::fake(['*' => midtransEcho()]);

    midtransStart($owner, $booking)->assertOk();

    expect(DB::table('payment_attempts')->value('merchant_id'))->toBe('G000000001');
});

it('quarantines a Charge response that reuses another attempt transaction id instead of failing with a server error', function () {
    [$firstOwner, $firstBooking] = midtransPayable();
    [$owner, $booking] = midtransPayable();
    Http::fake(['*' => midtransEcho('trx-duplicate')]);
    midtransStart($firstOwner, $firstBooking, 'first')->assertOk()->assertJsonPath('data.status', 'pending');

    $data = midtransStart($owner, $booking, 'second')->assertOk()->json('data');

    expect($data)->toMatchArray(['status' => 'unknown', 'reason' => 'provider_reference_conflict'])->and($data['instruction'])->toBe(['presentation_type' => 'unavailable']);
    $attempts = DB::table('payment_attempts')->orderBy('created_at')->orderBy('attempt_number')->get()->keyBy(fn ($row) => $row->payment_intent_guid === DB::table('payment_intents')->where('booking_guid', $firstBooking->guid)->value('guid') ? 'first' : 'second');
    expect($attempts['first']->status)->toBe('pending')->and($attempts['first']->provider_reference)->toBe('trx-duplicate')
        ->and($attempts['second']->status)->toBe('unknown')->and($attempts['second']->provider_reference)->toBeNull()
        ->and($attempts['second']->instruction_ciphertext)->toBeNull()->and($attempts['second']->last_error_code)->toBe('provider_reference_conflict');
    expect(DB::table('payment_intents')->where('booking_guid', $booking->guid)->value('status'))->toBe('needs_review')
        ->and(DB::table('payment_intents')->where('booking_guid', $firstBooking->guid)->value('status'))->toBe('processing');
    // No second order and no further Charge, whether the customer repeats the key or uses a new one.
    $replay = midtransStart($owner, $booking, 'second')->assertOk()->json('data');
    expect($replay['attempt_guid'])->toBe($data['attempt_guid']);
    midtransStart($owner, $booking, 'third')->assertConflict()->assertJsonPath('code', 'payment_needs_review');
    Http::assertSentCount(2);
    $this->assertDatabaseCount('payment_attempts', 2);
});
