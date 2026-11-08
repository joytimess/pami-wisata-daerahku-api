<?php

use App\Jobs\ProcessDokuPaymentEvent;
use App\Jobs\ReconcileDokuPayment;
use App\Models\Destination;
use App\Services\BookingService;
use App\Services\Doku\DokuClient;
use App\Services\DokuPaymentService;
use App\Services\PaymentEligibility;
use App\Services\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 08:00:00', 'UTC'));
    config(['operations.demo_payments_enabled' => false, 'doku.environment' => 'sandbox']);
    Http::preventStrayRequests();
});

function dokuBooking(): array
{
    [$destination, $date] = seedDestination(capacity: 5, price: 10000, days: 5);
    $owner = makeUser();
    $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date, 2) + ['idempotency_key' => (string) Str::uuid()]);
    $booking = app(BookingService::class)->decide(makeUser(2), $booking->guid, true);

    return [$owner, app(TicketService::class)->booking($booking->guid)];
}

function dokuClientFake(): object
{
    $client = Mockery::mock(DokuClient::class)->makePartial();
    $client->shouldReceive('readinessReason')->andReturn(null)->byDefault();
    $client->shouldReceive('configured')->andReturn(true)->byDefault();
    $client->shouldReceive('environment')->andReturn('sandbox')->byDefault();
    app()->instance(DokuClient::class, $client);

    return $client;
}

function dokuCreateResult(object $attempt, object $booking): array
{
    return ['status' => 'pending', 'provider_reference' => '988123456789', 'expires_at' => $booking->payment_expires_at,
        'instruction' => ['presentation_type' => 'virtual_account', 'bank_code' => 'bni', 'va_number' => '988123456789', 'copyable' => true,
            '_provider' => ['partner_service_id' => '988', 'customer_no' => '123456789']]];
}

function dokuStart(object $owner, object $booking, string $key = 'start'): array
{
    return test()->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts', ['method_code' => 'virtual_account', 'channel' => 'bni'],
        bearer($owner) + ['Idempotency-Key' => $key])->assertOk()->json('data');
}

function dokuOutcome(string $attemptGuid, array $overrides = []): array
{
    $attempt = DB::table('payment_attempts')->where('guid', $attemptGuid)->first();

    return array_replace(['status' => 'paid', 'merchant_reference' => $attempt->merchant_reference, 'provider_reference' => $attempt->provider_reference,
        'amount' => (int) $attempt->amount, 'currency' => $attempt->currency, 'occurred_at' => now()->toIso8601String(),
        'event_key' => 'capture-'.$attemptGuid, 'payload_hash' => hash('sha256', 'capture-'.$attemptGuid)], $overrides);
}

it('protects creation with authentication ownership and server authority', function () {
    [$owner, $booking] = dokuBooking();
    $path = '/api/v1/bookings/'.$booking->guid.'/payment-attempts';
    $this->postJson($path)->assertUnauthorized();
    $this->postJson($path, [], bearer(makeUser()))->assertNotFound();
    $this->postJson($path, [], bearer(makeUser(1)))->assertNotFound();
    $this->postJson($path, [], bearer($owner))->assertUnprocessable();
    $this->postJson($path, ['method_code' => 'virtual_account', 'channel' => 'bni', 'amount' => 1, 'environment' => 'production'],
        bearer($owner) + ['Idempotency-Key' => 'bad'])->assertUnprocessable()->assertJsonValidationErrors(['amount', 'environment']);
    $this->assertDatabaseCount('payment_attempts', 0);
});

it('persists before calling DOKU outside locks and recovers encrypted instructions', function () {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing(function ($attempt, $booking) {
        expect(DB::transactionLevel())->toBe(1); // RefreshDatabase wraps the test, not the provider call.
        test()->assertDatabaseHas('payment_attempts', ['guid' => $attempt->guid, 'status' => 'initiating']);

        return dokuCreateResult($attempt, $booking);
    });
    $first = dokuStart($owner, $booking);
    expect(dokuStart($owner, $booking))->toBe($first);
    expect(dokuStart($owner, $booking, 'another-key')['attempt_guid'])->toBe($first['attempt_guid']);
    expect($first)->toMatchArray(['provider' => 'doku', 'environment' => 'sandbox', 'test_only' => true, 'status' => 'pending', 'amount' => 20000]);
    expect($first['instruction'])->not->toHaveKey('_provider');
    expect(DB::table('payment_attempts')->value('instruction_ciphertext'))->not->toContain('988123456789');
    expect(DB::table('idempotency_records')->where('operation', 'doku_payment.initiate')->value('response_body'))->not->toContain('988123456789');
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/payment-intent', bearer($owner))->assertOk()
        ->assertJsonPath('data.instruction.va_number', '988123456789');
    $this->assertDatabaseCount('payment_attempts', 1);
});

it('rejects reused keys across bookings and expired bookings before calling DOKU', function () {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    dokuStart($owner, $booking);
    [, $other] = dokuBooking();
    DB::table('bookings')->where('guid', $other->guid)->update(['user_guid' => $owner->guid]);
    $path = '/api/v1/bookings/'.$other->guid.'/payment-attempts';
    $input = ['method_code' => 'virtual_account', 'channel' => 'bni'];
    $this->postJson($path, $input, bearer($owner) + ['Idempotency-Key' => 'start'])->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
    DB::table('bookings')->where('guid', $other->guid)->update(['payment_expires_at' => now()->subSecond()]);
    $this->postJson($path, $input, bearer($owner) + ['Idempotency-Key' => 'expired'])->assertConflict()->assertJsonPath('code', 'payment_window_closed');
});

it('keeps an uncertain create unresolved on retry', function () {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturn(['status' => 'unknown', 'reason' => 'provider_timeout']);
    $first = dokuStart($owner, $booking);
    expect($first['status'])->toBe('unknown');
    expect(dokuStart($owner, $booking, 'retry')['attempt_guid'])->toBe($first['attempt_guid']);
    $this->assertDatabaseCount('payment_attempts', 1);
});

it('durably records events before queueing and applies duplicate captures once', function () {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $attempt = dokuStart($owner, $booking);
    Queue::fake([ProcessDokuPaymentEvent::class]);
    $service = app(DokuPaymentService::class);
    $event = $service->receive(dokuOutcome($attempt['attempt_guid']));
    expect($service->receive(dokuOutcome($attempt['attempt_guid'])))->toBe($event);
    expect($service->receive(dokuOutcome($attempt['attempt_guid'], ['payload_hash' => hash('sha256', 'different-whitespace')])))->toBe($event);
    $this->assertDatabaseHas('payment_events', ['guid' => $event, 'disposition' => 'recorded']);
    Queue::assertPushed(ProcessDokuPaymentEvent::class);
    $service->apply($event);
    $service->apply($event);
    $this->assertDatabaseHas('bookings', ['guid' => $booking->guid, 'status' => 'confirmed']);
    expect(app(PaymentEligibility::class)->status($booking))->toBe('paid');
    expect(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'confirmed')->count())->toBe(1);
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/tickets', bearer($owner))->assertOk()->assertJsonPath('data.payment_status', 'paid');
    $tokens = DB::table('booking_tickets')->pluck('qr_token_hash')->all();
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/tickets', bearer($owner))->assertOk();
    expect(DB::table('booking_tickets')->pluck('qr_token_hash')->all())->toBe($tokens);
    $this->assertDatabaseCount('payment_events', 1);
});

it('quarantines mismatching and late captures without tickets', function (array $override, string $bookingStatus) {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $attempt = dokuStart($owner, $booking);
    DB::table('bookings')->where('guid', $booking->guid)->update(['status' => $bookingStatus]);
    if (isset($override['late'])) {
        unset($override['late']);
        $override['occurred_at'] = CarbonImmutable::parse($booking->payment_expires_at)->addSecond()->toIso8601String();
        $this->travelTo(CarbonImmutable::parse($booking->payment_expires_at)->addMinutes(1));
    }
    app(DokuPaymentService::class)->receive(dokuOutcome($attempt['attempt_guid'], $override));
    $this->assertDatabaseHas('payment_intents', ['guid' => $attempt['intent_guid'], 'status' => 'needs_review']);
    $this->assertDatabaseHas('bookings', ['guid' => $booking->guid, 'status' => $bookingStatus]);
    expect(app(PaymentEligibility::class)->status($booking))->toBe('unverified');
    expect(DB::table('booking_tickets')->whereNotNull('qr_token_hash')->count())->toBe(0);
})->with(['amount' => [['amount' => 1], 'awaiting_payment'], 'currency' => [['currency' => 'USD'], 'awaiting_payment'],
    'cancelled' => [[], 'cancelled'], 'late' => [['late' => true], 'awaiting_payment']]);

it('accepts a timely payment delivered in the held verification grace period', function () {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $attempt = dokuStart($owner, $booking);
    $outcome = dokuOutcome($attempt['attempt_guid']);
    $this->travelTo(CarbonImmutable::parse($booking->payment_expires_at)->addMinutes(2));
    DB::table('bookings')->where('guid', $booking->guid)->update(['status' => 'payment_verification']);
    app(DokuPaymentService::class)->receive($outcome);
    $this->assertDatabaseHas('bookings', ['guid' => $booking->guid, 'status' => 'confirmed']);
});

it('retains paid evidence and revokes unused QR on a verified reversal', function () {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $attempt = dokuStart($owner, $booking);
    $service = app(DokuPaymentService::class);
    $service->receive(dokuOutcome($attempt['attempt_guid']));
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/tickets', bearer($owner))->assertOk();
    $service->receive(dokuOutcome($attempt['attempt_guid'], ['status' => 'reversed', 'event_key' => 'reversal', 'payload_hash' => hash('sha256', 'reversal')]));
    expect(app(PaymentEligibility::class)->status($booking))->toBe('reversed');
    $this->assertDatabaseHas('payment_events', ['event_type' => 'capture', 'disposition' => 'applied']);
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/tickets', bearer($owner))->assertOk()->assertJsonPath('data.ticket_state', 'revoked');
    expect(DB::table('booking_tickets')->whereNull('qr_revoked_at')->whereNotNull('qr_token_hash')->count())->toBe(0);
});

it('isolates simulator outcomes from DOKU and preserves existing payments when new issuance is disabled', function () {
    [$owner, $booking] = dokuBooking();
    $client = dokuClientFake();
    $client->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $attempt = dokuStart($owner, $booking);
    config(['operations.demo_payments_enabled' => true]);
    $this->postJson('/api/v1/bookings/'.$booking->guid.'/demo-payment/simulate', ['attempt_guid' => $attempt['attempt_guid'], 'outcome' => 'success'],
        bearer($owner) + ['Idempotency-Key' => 'demo'])->assertConflict();
    config(['operations.demo_payments_enabled' => false, 'doku.enabled' => false]);
    $client->shouldReceive('readinessReason')->andReturn('channel_unavailable');
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/payment-intent', bearer($owner))->assertOk()
        ->assertJsonPath('data.can_pay', false)->assertJsonPath('data.instruction.va_number', '988123456789');
    app(DokuPaymentService::class)->receive(dokuOutcome($attempt['attempt_guid']));
    expect(app(PaymentEligibility::class)->status($booking))->toBe('paid');
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/payment-intent', bearer($owner))->assertOk()
        ->assertJsonPath('data.instruction.presentation_type', 'unavailable');
});

it('keeps durable events recoverable when queue publication fails', function () {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $attempt = dokuStart($owner, $booking);
    Queue::shouldReceive('connection')->andThrow(new RuntimeException('queue unavailable'));
    $event = app(DokuPaymentService::class)->receive(dokuOutcome($attempt['attempt_guid']));
    $this->assertDatabaseHas('payment_events', ['guid' => $event, 'disposition' => 'recorded']);
    $this->artisan('payments:reconcile-doku')->assertSuccessful();
    $this->assertDatabaseHas('bookings', ['guid' => $booking->guid, 'status' => 'confirmed']);
});

it('does not mutate an old environment when a queued event reaches another deployment', function () {
    [$owner, $booking] = dokuBooking();
    $client = dokuClientFake();
    $client->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $attempt = dokuStart($owner, $booking);
    Queue::fake([ProcessDokuPaymentEvent::class]);
    $event = app(DokuPaymentService::class)->receive(dokuOutcome($attempt['attempt_guid']));
    $client->shouldReceive('environment')->andReturn('production');
    app(DokuPaymentService::class)->apply($event);
    $this->assertDatabaseHas('payment_events', ['guid' => $event, 'disposition' => 'quarantined']);
    $this->assertDatabaseHas('payment_intents', ['guid' => $attempt['intent_guid'], 'environment' => 'sandbox', 'status' => 'processing']);
    $this->assertDatabaseHas('bookings', ['guid' => $booking->guid, 'status' => 'awaiting_payment']);
});

it('puts a superseded failed attempt that later captures into review', function () {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->twice()->andReturnUsing(function ($attempt, $booking) {
        return array_replace(dokuCreateResult($attempt, $booking), ['provider_reference' => 'VA-'.$attempt->attempt_number]);
    });
    $old = dokuStart($owner, $booking);
    app(DokuPaymentService::class)->receive(dokuOutcome($old['attempt_guid'], ['status' => 'failed', 'event_key' => 'failed', 'payload_hash' => hash('sha256', 'failed')]));
    $new = dokuStart($owner, $booking, 'retry');
    expect($new['attempt_guid'])->not->toBe($old['attempt_guid']);
    app(DokuPaymentService::class)->receive(dokuOutcome($old['attempt_guid']));
    $this->assertDatabaseHas('payment_events', ['payment_attempt_guid' => $old['attempt_guid'], 'event_type' => 'capture', 'disposition' => 'unapplied']);
    $this->assertDatabaseHas('payment_intents', ['guid' => $old['intent_guid'], 'status' => 'needs_review']);
    $this->assertDatabaseHas('bookings', ['guid' => $booking->guid, 'status' => 'awaiting_payment']);
});

it('reconciles an existing reference once without another create', function () {
    [$owner, $booking] = dokuBooking();
    $client = dokuClientFake();
    $client->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $attempt = dokuStart($owner, $booking);
    $client->shouldReceive('status')->once()->andReturn(dokuOutcome($attempt['attempt_guid']));
    $this->travel(3)->minutes();
    app(DokuPaymentService::class)->reconcile($attempt['attempt_guid']);
    app(DokuPaymentService::class)->reconcile($attempt['attempt_guid']);
    $this->assertDatabaseHas('payment_attempts', ['guid' => $attempt['attempt_guid'], 'status' => 'paid', 'reconcile_count' => 1]);
    $this->assertDatabaseCount('payment_attempts', 1);
});

it('hides instructions beyond the booking deadline and disables quarantined methods', function () {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing(fn ($attempt, $booking) => array_replace(dokuCreateResult($attempt, $booking),
        ['expires_at' => CarbonImmutable::parse($booking->payment_expires_at)->addHour()->toIso8601String()]));
    $attempt = dokuStart($owner, $booking);
    expect($attempt['status'])->toBe('unknown');
    expect($attempt['instruction'])->toBe(['presentation_type' => 'unavailable']);
    app(DokuPaymentService::class)->receive(dokuOutcome($attempt['attempt_guid'], ['amount' => 1]));
    $this->getJson('/api/v1/payments/methods?booking_guid='.$booking->guid, bearer($owner))->assertOk()
        ->assertJsonPath('data.can_pay', false)->assertJsonPath('data.reason', 'payment_needs_review')
        ->assertJsonPath('data.methods.1.available', false);
});

it('ignores stale pending events after failure and never resets a newer active attempt', function () {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->twice()->andReturnUsing(fn ($attempt, $booking) => array_replace(dokuCreateResult($attempt, $booking), ['provider_reference' => 'VA-'.$attempt->attempt_number]));
    $first = dokuStart($owner, $booking);
    $service = app(DokuPaymentService::class);
    $service->receive(dokuOutcome($first['attempt_guid'], ['status' => 'failed', 'event_key' => 'failed-1', 'payload_hash' => hash('sha256', 'failed-1')]));
    $next = dokuStart($owner, $booking, 'retry');
    $service->receive(dokuOutcome($first['attempt_guid'], ['status' => 'pending', 'event_key' => 'stale', 'payload_hash' => hash('sha256', 'stale')]));
    $service->receive(dokuOutcome($first['attempt_guid'], ['status' => 'failed', 'event_key' => 'failed-2', 'payload_hash' => hash('sha256', 'failed-2')]));
    $this->assertDatabaseHas('payment_attempts', ['guid' => $first['attempt_guid'], 'status' => 'failed']);
    $this->assertDatabaseHas('payment_attempts', ['guid' => $next['attempt_guid'], 'status' => 'pending']);
    $this->assertDatabaseHas('payment_intents', ['guid' => $first['intent_guid'], 'status' => 'processing']);
});

it('does not query payment tables again for projected unpaid booking rows', function () {
    [, $booking] = dokuBooking();
    $booking->has_payment = false;
    DB::enableQueryLog();
    $service = app(PaymentEligibility::class);
    expect($service->status($booking))->toBe('unverified');
    expect($service->provenance($booking)['payment_provider'])->toBeNull();
    expect(DB::getQueryLog())->toBeEmpty();
    DB::disableQueryLog();
});

it('limits admin payment investigation to assigned destinations without exposing payment secrets', function () {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    dokuStart($owner, $booking);
    $path = '/api/v1/admin/bookings/'.$booking->guid.'/payment-intent';
    $this->getJson($path)->assertUnauthorized();
    $this->getJson($path, bearer($owner))->assertForbidden();
    $admin = makeUser(1);
    $this->getJson($path, bearer($admin))->assertNotFound();
    $destination = Destination::findOrFail($booking->destination_guid);
    assignTestDestination($admin, $destination);
    $response = $this->getJson($path, bearer($admin))->assertOk()->assertJsonPath('data.environment', 'sandbox')
        ->assertJsonPath('data.attempts.0.status', 'pending');
    expect($response->getContent())->not->toContain('988123456789', 'instruction_ciphertext', 'visitor@example.com');
    $this->getJson($path, bearer(makeUser(2)))->assertOk();
});

it('separates late collected funds from recognized sandbox revenue', function () {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $attempt = dokuStart($owner, $booking);
    $this->travelTo(CarbonImmutable::parse($booking->payment_expires_at)->addMinutes(1));
    app(DokuPaymentService::class)->receive(dokuOutcome($attempt['attempt_guid']));
    $this->getJson('/api/v1/admin/dashboard/financial?environment=sandbox', bearer(makeUser(2)))->assertOk()
        ->assertJsonPath('data.financial.gross_collected', 20000)->assertJsonPath('data.financial.gross_revenue', 0)
        ->assertJsonPath('data.financial.unapplied_collected', 20000)->assertJsonPath('data.financial.test_only', true);
});

it('defers a verified occurrence within clock tolerance until its time arrives', function () {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $attempt = dokuStart($owner, $booking);
    $service = app(DokuPaymentService::class);
    $event = $service->receive(dokuOutcome($attempt['attempt_guid'], ['occurred_at' => now()->addMinutes(2)->toIso8601String()]));
    $this->assertDatabaseHas('payment_events', ['guid' => $event, 'disposition' => 'recorded']);
    $this->assertDatabaseHas('bookings', ['guid' => $booking->guid, 'status' => 'awaiting_payment']);
    $this->travel(2)->minutes();
    $service->apply($event);
    $this->assertDatabaseHas('bookings', ['guid' => $booking->guid, 'status' => 'confirmed']);
});

it('creates through real transport normalization and finalizes only a valid signed HTTP callback', function () {
    [$owner, $booking] = dokuBooking();
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $path = tempnam(sys_get_temp_dir(), 'doku-integration-');
    file_put_contents($path, $pem);
    try {
        config(['app.debug' => false, 'doku.enabled' => true, 'doku.client_id' => 'MCH-TEST', 'doku.secret_key' => 'fixture-secret',
            'doku.merchant_private_key_path' => $path, 'doku.merchant_private_key_passphrase' => '',
            'doku.partner_service_id' => '98829172', 'doku.customer_prefix' => '3', 'doku.channels' => ['va_bni'],
            'doku.webhook_url' => 'https://sandbox.example.com/api/v1/webhooks/doku/va']);
        $va = '9882917230000000000000000001';
        Http::fake([
            'https://api-sandbox.doku.com/authorization/v1/access-token/b2b' => Http::response(['responseCode' => '2007300', 'accessToken' => 'fixture-token', 'tokenType' => 'Bearer', 'expiresIn' => 900]),
            'https://api-sandbox.doku.com/virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va' => function ($request) use ($va) {
                return Http::response(['responseCode' => '2002700', 'virtualAccountData' => array_replace($request->data(), [
                    'customerNo' => '30000000000000000001', 'virtualAccountNo' => $va])]);
            },
        ]);
        $attempt = dokuStart($owner, $booking);
        $record = DB::table('payment_attempts')->where('guid', $attempt['attempt_guid'])->first();
        $payload = ['partnerServiceId' => '98829172', 'customerNo' => '30000000000000000001', 'virtualAccountNo' => $va,
            'trxId' => $record->merchant_reference, 'paymentRequestId' => '1234567890', 'paidAmount' => ['value' => '20000.00', 'currency' => 'IDR'],
            'virtualAccountTrxType' => 'C', 'trxDateTime' => now()->toIso8601String(), 'additionalInfo' => ['channel' => 'VIRTUAL_ACCOUNT_BNI']];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = now()->toIso8601String();
        $signature = base64_encode(hash_hmac('sha512', 'POST:/api/v1/webhooks/doku/va:fixture-token:'.hash('sha256', $body).':'.$timestamp, 'fixture-secret', true));
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PARTNER_ID' => 'MCH-TEST', 'HTTP_CHANNEL_ID' => 'VA004',
            'HTTP_X_EXTERNAL_ID' => '20261108000001', 'HTTP_AUTHORIZATION' => 'Bearer fixture-token', 'HTTP_X_TIMESTAMP' => $timestamp, 'HTTP_X_SIGNATURE' => $signature];
        $this->call('POST', '/api/v1/webhooks/doku/va', [], [], [], $headers, str_replace('20000.00', '10000.00', $body))->assertUnauthorized();
        $this->assertDatabaseCount('payment_events', 0);
        $this->call('POST', '/api/v1/webhooks/doku/va', [], [], [], $headers, $body)->assertOk()->assertJsonPath('responseCode', '2002500');
        $this->call('POST', '/api/v1/webhooks/doku/va', [], [], [], $headers, $body)->assertOk();
        $this->assertDatabaseCount('payment_events', 1);
        $this->assertDatabaseHas('bookings', ['guid' => $booking->guid, 'status' => 'confirmed']);
        Http::assertSentCount(2);
    } finally {
        unlink($path);
    }
});

it('queues bounded reconciliation work and keeps instructions during verified pending inquiry', function () {
    [$owner, $booking] = dokuBooking();
    $client = dokuClientFake();
    $client->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $attempt = dokuStart($owner, $booking);
    $this->travel(3)->minutes();
    config(['doku.reconciliation_enabled' => true]);
    Queue::fake([ReconcileDokuPayment::class]);
    $this->artisan('payments:reconcile-doku', ['--limit' => 1])->assertSuccessful();
    $this->artisan('payments:reconcile-doku', ['--limit' => 1])->assertSuccessful();
    Queue::assertPushed(ReconcileDokuPayment::class, fn ($job) => $job->attemptGuid === $attempt['attempt_guid']);
    Queue::assertPushed(ReconcileDokuPayment::class, 1);
    $client->shouldReceive('status')->once()->andReturn(['status' => 'pending']);
    app(DokuPaymentService::class)->reconcile($attempt['attempt_guid']);
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/payment-intent', bearer($owner))->assertOk()
        ->assertJsonPath('data.last_attempt.status', 'pending')->assertJsonPath('data.instruction.va_number', '988123456789');
});
