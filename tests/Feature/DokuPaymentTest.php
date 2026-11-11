<?php

use App\Jobs\IssueBookingTicketQr;
use App\Jobs\ProcessDokuPaymentEvent;
use App\Jobs\ReconcileDokuPayment;
use App\Models\Destination;
use App\Services\BookingService;
use App\Services\Doku\DokuClient;
use App\Services\DokuPaymentService;
use App\Services\PaymentEligibility;
use App\Services\TicketService;
use App\Support\BookingRules;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\PDF;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 08:00:00', 'UTC'));
    config(['operations.demo_payments_enabled' => false, 'doku.environment' => 'sandbox', 'doku.verified_channels' => ['va_bni']]);
    Http::preventStrayRequests();
});

function dokuBooking(): array
{
    [$destination, $date] = seedDestination(capacity: 5, price: 10000, days: 5);
    $owner = makeUser();
    $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date, 2) + ['idempotency_key' => (string) Str::uuid()]);
    $booking = app(BookingService::class)->decide(makeUser(2), $booking->guid, true);
    // The legacy DOKU assertions below were written for the former one hour approval window; the 15 minute window is Midtrans policy.
    DB::table('bookings')->where('guid', $booking->guid)->update(['payment_expires_at' => now()->addHour(), 'notification_grace_expires_at' => now()->addMinutes(75)]);

    return [$owner, app(TicketService::class)->booking($booking->guid)];
}

function dokuClientFake(): object
{
    $client = Mockery::mock(DokuClient::class)->makePartial();
    $client->shouldReceive('readinessReason')->andReturn(null)->byDefault();
    $client->shouldReceive('configured')->andReturn(true)->byDefault();
    $client->shouldReceive('environment')->andReturn('sandbox')->byDefault();
    $client->shouldReceive('expiryWindowReason')->andReturn(null)->byDefault();
    app()->instance(DokuClient::class, $client);

    return $client;
}

function dokuCreateResult(object $attempt, object $booking): array
{
    return ['status' => 'pending', 'provider_reference' => '988123456789', 'expires_at' => $attempt->instruction_expires_at,
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

it('rejects sensitive client overrides before any provider call', function (string $field) {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->andReturn(['status' => 'unknown', 'reason' => 'fixture_unexpected_create']);

    $this->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts',
        ['method_code' => 'virtual_account', 'channel' => 'bni', $field => 'client-override'],
        bearer($owner) + ['Idempotency-Key' => 'override'])->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseCount('payment_attempts', 0);
    $this->assertDatabaseCount('payment_intents', 0);
})->with(['amount', 'currency', 'provider', 'environment', 'status', 'paid_at', 'merchant_id', 'expiry', 'fee', 'total_amount', 'invoice_id', 'booking_guid', 'paid']);

it('repairs a legacy invoice only on explicit create and returns the unchanged server snapshot', function () {
    [$owner, $booking] = dokuBooking();
    DB::table('invoices')->where('booking_guid', $booking->guid)->delete();
    DB::table('destinations')->where('guid', $booking->destination_guid)->update(['ticket_price' => 99000]);
    $headers = bearer($owner);
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/documents', $headers)->assertOk()->assertJsonPath('data.invoice', null);
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/payment-intent', $headers)->assertOk();
    $this->getJson('/api/v1/payments/methods?booking_guid='.$booking->guid, $headers)->assertOk();
    $this->assertDatabaseCount('invoices', 0);
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing(function ($attempt, $booking) {
        expect(DB::table('invoices')->where('booking_guid', $booking->guid)->value('invoice_number'))->not->toBeNull();

        return dokuCreateResult($attempt, $booking);
    });

    $result = dokuStart($owner, $booking);

    expect($result)->toMatchArray(['booking_number' => $booking->booking_number, 'quantity' => 2, 'unit_price' => 10000,
        'subtotal' => 20000, 'service_fee' => 0, 'total_amount' => 20000, 'paid_at' => null,
        'applied_attempt_guid' => null, 'receipt_available' => false, 'can_create' => false, 'can_switch' => false]);
    $invoice = DB::table('invoices')->first();
    expect($result['invoice_number'])->toBe($invoice->invoice_number);
    expect(dokuStart($owner, $booking, 'retry')['invoice_number'])->toBe($invoice->invoice_number);
    $this->assertDatabaseCount('invoices', 1);
    expect($invoice->pdf_path)->toBeNull();
    $this->assertDatabaseCount('tickets', 0);
});

it('preserves an existing invoice number across a payment attempt', function () {
    [$owner, $booking] = dokuBooking();
    DB::table('invoices')->where('booking_guid', $booking->guid)->delete();
    DB::table('invoices')->insert(['guid' => (string) Str::uuid(), 'booking_guid' => $booking->guid,
        'invoice_number' => 'LEGACY-UNCHANGED', 'status' => 'issued', 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');

    expect(dokuStart($owner, $booking)['invoice_number'] ?? null)->toBe('LEGACY-UNCHANGED');
    $this->assertDatabaseCount('invoices', 1);
});

it('reports creation and switching safely while a previous attempt remains unresolved', function (string $status) {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $attempt = dokuStart($owner, $booking);
    DB::table('payment_attempts')->where('guid', $attempt['attempt_guid'])->update(['status' => $status]);

    $this->getJson('/api/v1/bookings/'.$booking->guid.'/payment-intent', bearer($owner))->assertOk()
        ->assertJsonPath('data.can_create', false)->assertJsonPath('data.can_switch', false)
        ->assertJsonPath('data.switch_reason', 'payment_attempt_unresolved');
})->with(['initiating', 'pending', 'unknown']);

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

it('retains create instructions when a concurrent refresh resolves before creation returns', function (string $inquiryStatus) {
    [$owner, $booking] = dokuBooking();
    $client = dokuClientFake();
    $client->shouldReceive('status')->once()->andReturn(['status' => $inquiryStatus]);
    $client->shouldReceive('create')->once()->andReturnUsing(function ($attempt, $booking) use ($owner) {
        app(DokuPaymentService::class)->refresh($owner, $booking->guid, $attempt->guid);

        return dokuCreateResult($attempt, $booking);
    });

    $result = dokuStart($owner, $booking);

    expect($result['status'])->toBe('pending');
    expect($result['instruction']['va_number'] ?? null)->toBe('988123456789');
    $this->assertDatabaseHas('payment_attempts', ['guid' => $result['attempt_guid'], 'status' => 'pending', 'provider_reference' => '988123456789']);
    expect(dokuStart($owner, $booking, 'retry')['attempt_guid'])->toBe($result['attempt_guid']);
    $this->assertDatabaseCount('payment_attempts', 1);
    $this->assertDatabaseHas('bookings', ['guid' => $booking->guid, 'status' => 'awaiting_payment']);
})->with(['unknown', 'pending']);

it('keeps a verified capture when the create response arrives afterward', function () {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing(function ($attempt, $booking) {
        app(DokuPaymentService::class)->receive(dokuOutcome($attempt->guid, ['provider_reference' => '988123456789']));

        return dokuCreateResult($attempt, $booking);
    });

    $result = dokuStart($owner, $booking);

    expect($result)->toMatchArray(['status' => 'paid', 'booking_status' => 'confirmed', 'payment_status' => 'paid',
        'instruction' => ['presentation_type' => 'unavailable']]);
    $this->assertDatabaseHas('payment_attempts', ['guid' => $result['attempt_guid'], 'status' => 'paid', 'provider_reference' => '988123456789', 'instruction_ciphertext' => null]);
    $this->assertDatabaseCount('payment_events', 1);
});

it('retains only valid matching earlier provider expiry after a timely callback finalized payment', function (string $providerExpiry, string $reference, string $expected) {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing(function ($attempt, $booking) use ($providerExpiry, $reference) {
        test()->travel(30)->seconds();
        app(DokuPaymentService::class)->receive(dokuOutcome($attempt->guid, ['provider_reference' => '988123456789']));
        test()->travel(30)->seconds();

        return array_replace(dokuCreateResult($attempt, $booking), ['expires_at' => $providerExpiry, 'provider_reference' => $reference]);
    });

    $result = dokuStart($owner, $booking);

    expect($result['status'])->toBe('paid');
    expect($result['expires_at'])->toBe($expected);
    expect($result['instruction'])->toBe(['presentation_type' => 'unavailable']);
    $this->assertDatabaseHas('payment_attempts', ['guid' => $result['attempt_guid'], 'status' => 'paid',
        'instruction_expires_at' => CarbonImmutable::parse($expected)->toDateTimeString(), 'instruction_ciphertext' => null]);
    $this->assertDatabaseHas('payment_intents', ['guid' => $result['intent_guid'], 'status' => 'paid', 'applied_attempt_guid' => $result['attempt_guid']]);
})->with([
    'earlier future provider expiry' => ['2026-11-08T08:10:00+00:00', '988123456789', '2026-11-08T08:10:00+00:00'],
    'different provider reference' => ['2026-11-08T08:10:00+00:00', 'OTHER-REFERENCE', '2026-11-08T08:30:00+00:00'],
    'malformed expiry' => ['invalid-expiry', '988123456789', '2026-11-08T08:30:00+00:00'],
    'expired provider window' => ['2026-11-08T08:00:00+00:00', '988123456789', '2026-11-08T08:30:00+00:00'],
    'provider extension' => ['2026-11-08T08:45:00+00:00', '988123456789', '2026-11-08T08:30:00+00:00'],
]);

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
    expect($response->getContent())->not->toContain('988123456789')->not->toContain('instruction_ciphertext')->not->toContain('visitor@example.com');
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
        $client = Mockery::mock(DokuClient::class)->makePartial();
        $client->shouldReceive('expiryWindowReason')->andReturn(null);
        app()->instance(DokuClient::class, $client);
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

it('persists the effective instruction window once while keeping the booking deadline on the intent', function (int $minutes, string $expected) {
    [$owner, $booking] = dokuBooking();
    DB::table('bookings')->where('guid', $booking->guid)->update(['payment_expires_at' => now()->addMinutes($minutes)]);
    $booking = app(TicketService::class)->booking($booking->guid);
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');

    $first = dokuStart($owner, $booking);

    expect($first['expires_at'])->toBe($expected);
    $this->assertDatabaseHas('payment_attempts', ['guid' => $first['attempt_guid'], 'instruction_expires_at' => substr(str_replace('T', ' ', $expected), 0, 19)]);
    $this->assertDatabaseHas('payment_intents', ['guid' => $first['intent_guid'], 'expires_at' => $booking->payment_expires_at]);
    $this->travel(5)->minutes();
    expect(dokuStart($owner, $booking))->toBe($first);
    expect(dokuStart($owner, $booking, 'new-key')['expires_at'])->toBe($expected);
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/payment-intent', bearer($owner))->assertOk()
        ->assertJsonPath('data.expires_at', CarbonImmutable::parse($booking->payment_expires_at, 'UTC')->toIso8601String())
        ->assertJsonPath('data.last_attempt.expires_at', $expected)->assertJsonPath('data.can_create', false);
})->with(['thirty-minute maximum' => [60, '2026-11-08T08:30:00+00:00'], 'permitted short fixture window' => [12, '2026-11-08T08:12:00+00:00']]);

it('keeps the persisted instruction window consistent with the in-memory attempt at sub-second precision', function () {
    [$owner, $booking] = dokuBooking();
    $this->travelTo(CarbonImmutable::parse('2026-11-08 08:00:00.654321', 'UTC'));
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing(function ($attempt, $booking) {
        expect(CarbonImmutable::parse($attempt->instruction_expires_at)->microsecond)->toBe(0);

        return dokuCreateResult($attempt, $booking);
    });

    $result = dokuStart($owner, $booking);

    expect($result['status'])->toBe('pending');
    expect($result['expires_at'])->toBe('2026-11-08T08:30:00+00:00');
    $this->assertDatabaseHas('payment_attempts', ['guid' => $result['attempt_guid'], 'status' => 'pending', 'last_error_code' => null]);
});

it('caps instruction expiry at visit start in the destination snapshot timezone', function () {
    [$owner, $booking] = dokuBooking();
    $this->travelTo(CarbonImmutable::parse('2026-11-12 16:45:00', 'UTC'));
    DB::table('destinations')->where('guid', $booking->destination_guid)->update(['timezone' => 'UTC']);
    DB::table('bookings')->where('guid', $booking->guid)->update(['payment_expires_at' => now()->addHour(), 'destination_timezone_snapshot' => 'Asia/Jakarta']);
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');

    expect(dokuStart($owner, $booking)['expires_at'])->toBe('2026-11-12T17:00:00+00:00');
});

it('lets the provider shorten but never extend the persisted instruction window', function (int $minutes, string $status, string $expected) {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing(fn ($attempt, $booking) => array_replace(dokuCreateResult($attempt, $booking),
        ['expires_at' => now()->addMinutes($minutes)->toIso8601String()]));

    $result = dokuStart($owner, $booking);

    expect($result['status'])->toBe($status);
    expect($result['expires_at'])->toBe($expected);
    $this->travel(31)->minutes();
    expect(dokuStart($owner, $booking, 'expired-retry')['attempt_guid'])->toBe($result['attempt_guid']);
    $this->assertDatabaseCount('payment_attempts', 1);
})->with(['shorter provider window' => [10, 'pending', '2026-11-08T08:10:00+00:00'],
    'extension is unresolved' => [45, 'unknown', '2026-11-08T08:30:00+00:00']]);

it('reuses unresolved instructions on method switches after their local countdown expires', function (string $status) {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $first = dokuStart($owner, $booking);
    DB::table('payment_attempts')->where('guid', $first['attempt_guid'])->update(['status' => $status]);
    $this->travel(31)->minutes();

    $this->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts', ['method_code' => 'qris', 'channel' => 'qris'],
        bearer($owner) + ['Idempotency-Key' => 'switch'])->assertOk()->assertJsonPath('data.attempt_guid', $first['attempt_guid'])
        ->assertJsonPath('data.can_create', false)->assertJsonPath('data.can_switch', false);
    $this->assertDatabaseCount('payment_attempts', 1);
})->with(['initiating', 'pending', 'unknown']);

it('reviews capture at or after the instruction deadline and applies earlier capture delivered within grace', function (int $offset, string $expected) {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $attempt = dokuStart($owner, $booking);
    $occurredAt = CarbonImmutable::parse('2026-11-08 08:30:00', 'UTC')->addSeconds($offset)->toIso8601String();
    $this->travelTo(CarbonImmutable::parse('2026-11-08 09:02:00', 'UTC'));
    app(BookingService::class)->expireDue();

    app(DokuPaymentService::class)->receive(dokuOutcome($attempt['attempt_guid'], ['occurred_at' => $occurredAt]));

    $this->assertDatabaseHas('payment_intents', ['guid' => $attempt['intent_guid'], 'status' => $expected]);
    $this->assertDatabaseHas('payment_attempts', ['guid' => $attempt['attempt_guid'], 'status' => 'paid']);
    $this->assertDatabaseHas('payment_events', ['payment_attempt_guid' => $attempt['attempt_guid'], 'disposition' => $expected === 'paid' ? 'applied' : 'unapplied']);
})->with(['timely within grace' => [-1, 'paid'], 'exact deadline' => [0, 'needs_review'], 'after deadline' => [1, 'needs_review']]);

it('rejects an unsupported provider instruction window before reserving or sending a create', function () {
    [$owner, $booking] = dokuBooking();
    $client = dokuClientFake();
    $client->shouldReceive('expiryWindowReason')->andReturn('provider_expiry_window_unverified');
    $client->shouldReceive('create')->andReturn(['status' => 'unknown', 'reason' => 'fixture_unexpected_create']);

    $this->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts', ['method_code' => 'virtual_account', 'channel' => 'bni'],
        bearer($owner) + ['Idempotency-Key' => 'unsupported-window'])->assertConflict()->assertJsonPath('code', 'provider_expiry_window_unverified');
    $this->assertDatabaseCount('payment_attempts', 0);
    $this->assertDatabaseCount('payment_intents', 0);
    Http::assertNothingSent();
});

it('keeps an older unknown attempt locked even if newer legacy history is terminal', function () {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->andReturnUsing('dokuCreateResult');
    $first = dokuStart($owner, $booking);
    $older = (array) DB::table('payment_attempts')->where('guid', $first['attempt_guid'])->first();
    DB::table('payment_attempts')->where('guid', $first['attempt_guid'])->update(['status' => 'unknown']);
    DB::table('payment_attempts')->insert(array_replace($older, ['guid' => (string) Str::uuid(), 'attempt_number' => 2,
        'merchant_reference' => (string) Str::uuid(), 'provider_request_id' => 'legacy-2', 'provider_reference' => null, 'status' => 'failed']));

    expect(dokuStart($owner, $booking, 'legacy-retry')['attempt_guid'])->toBe($first['attempt_guid']);
    $this->assertDatabaseCount('payment_attempts', 2);
});

it('exposes creation only for eligible snapshots and binds paid details to the applied intent', function () {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $headers = bearer($owner);
    $path = '/api/v1/bookings/'.$booking->guid.'/payment-intent';
    $this->getJson($path, $headers)->assertOk()->assertJsonPath('data.can_create', true);
    $attempt = dokuStart($owner, $booking);

    app(DokuPaymentService::class)->receive(dokuOutcome($attempt['attempt_guid']));

    $this->getJson($path, $headers)->assertOk()->assertJsonPath('data.can_create', false)
        ->assertJsonPath('data.paid_at', '2026-11-08T08:00:00+00:00')->assertJsonPath('data.applied_attempt_guid', $attempt['attempt_guid'])
        ->assertJsonPath('data.receipt_available', true);
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

it('never settles a booking from a paid outcome on a channel whose contract is unverified', function (string $method, string $channel) {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $attempt = dokuStart($owner, $booking);
    DB::table('payment_attempts')->where('guid', $attempt['attempt_guid'])->update(['method' => $method, 'channel' => $channel]);

    app(DokuPaymentService::class)->receive(dokuOutcome($attempt['attempt_guid'], ['method' => $method, 'channel' => $channel]));

    $this->assertDatabaseMissing('bookings', ['guid' => $booking->guid, 'status' => 'confirmed']);
    $this->assertDatabaseHas('payment_intents', ['guid' => $attempt['intent_guid'], 'status' => 'needs_review', 'applied_attempt_guid' => null]);
    $this->assertDatabaseMissing('payment_events', ['payment_attempt_guid' => $attempt['attempt_guid'], 'disposition' => 'applied']);
    $this->assertDatabaseCount('tickets', 0);
})->with([['qris', 'qris'], ['virtual_account', 'bca']]);

it('keeps existing BNI payments recoverable and creation closed when the issuance kill switch is on', function () {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $attempt = dokuStart($owner, $booking);
    config(['doku.enabled' => false, 'doku.channels' => [], 'doku.verified_channels' => []]);

    $this->getJson('/api/v1/bookings/'.$booking->guid.'/payment-intent', bearer($owner))->assertOk()
        ->assertJsonPath('data.can_create', false)->assertJsonPath('data.last_attempt.attempt_guid', $attempt['attempt_guid']);
    expect(app(DokuPaymentService::class)->receive(dokuOutcome($attempt['attempt_guid'])))->not->toBeEmpty();

    $this->assertDatabaseHas('payment_intents', ['guid' => $attempt['intent_guid'], 'status' => 'paid']);
});

function dokuQrisAttempt(array $attemptOverrides = []): array
{
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $attempt = dokuStart($owner, $booking);
    DB::table('payment_attempts')->where('guid', $attempt['attempt_guid'])->update($attemptOverrides + ['method' => 'qris', 'channel' => 'qris',
        'presentation_type' => 'qr_payload', 'instruction_ciphertext' => Crypt::encryptString(json_encode(['presentation_type' => 'qr_payload',
            'qr_content' => '00020101021226SENTINELQRPAYLOAD', 'copyable' => false], JSON_THROW_ON_ERROR))]);

    return [$owner, $booking, $attempt['attempt_guid']];
}

function dokuQrisExpectedSvg(): string
{
    return (new Writer(new ImageRenderer(new RendererStyle(360, 4),
        new SvgImageBackEnd)))->writeString('00020101021226SENTINELQRPAYLOAD', 'UTF-8', ErrorCorrectionLevel::M());
}

it('serves the persisted QRIS payload as a private no-store SVG to the booking owner only', function () {
    [$owner, $booking, $attempt] = dokuQrisAttempt();
    $path = '/api/v1/bookings/'.$booking->guid.'/payment-attempts/'.$attempt.'/qr';

    $response = $this->get($path, bearer($owner))->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('image/svg+xml')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->getContent())->toBe(dokuQrisExpectedSvg())
        ->and($response->getContent())->not->toContain('SENTINELQRPAYLOAD');
    $this->get($path, bearer(makeUser(2)))->assertNotFound();
    $this->get($path, bearer(makeUser(3)))->assertNotFound();
    $this->get($path)->assertUnauthorized();
});

it('refuses the payment QR for inactive, expired, foreign or non-QRIS attempts', function (string $case) {
    [$owner, $booking, $attempt] = match ($case) {
        'virtual account' => (function () {
            [$owner, $booking] = dokuBooking();
            dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');

            return [$owner, $booking, dokuStart($owner, $booking)['attempt_guid']];
        })(),
        'paid', 'failed', 'unknown' => dokuQrisAttempt(['status' => $case]),
        'other environment' => (function () {
            [$owner, $booking, $attempt] = dokuQrisAttempt();
            DB::table('payment_intents')->where('booking_guid', $booking->guid)->update(['environment' => 'production']);

            return [$owner, $booking, $attempt];
        })(),
        'attempt expired' => (function () {
            [$owner, $booking, $attempt] = dokuQrisAttempt();
            test()->travel(31)->minutes();

            return [$owner, $booking, $attempt];
        })(),
        'unknown attempt' => (function () {
            [$owner, $booking] = dokuQrisAttempt();

            return [$owner, $booking, (string) Str::uuid()];
        })(),
    };

    $this->get('/api/v1/bookings/'.$booking->guid.'/payment-attempts/'.$attempt.'/qr', bearer($owner))->assertNotFound();
})->with(['virtual account', 'paid', 'failed', 'unknown', 'other environment', 'attempt expired', 'unknown attempt']);

function dokuPaidBooking(): array
{
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $attempt = dokuStart($owner, $booking);
    app(DokuPaymentService::class)->receive(dokuOutcome($attempt['attempt_guid']));

    return [$owner, $booking, $attempt];
}

/** Captures the HTML given to DomPDF so receipt content can be asserted without parsing a PDF. */
function dokuCapturePdfHtml(?string &$html): void
{
    Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadHTML')->atLeast()->once()->andReturnUsing(function (string $given) use (&$html) {
        $html = $given;
        $pdf = Mockery::mock(PDF::class);
        $pdf->shouldReceive('output')->andReturn('%PDF-receipt-fixture');

        return $pdf;
    });
}

it('records one payment receipt event and one email delivery per verified payment, replacing the generic paid message', function () {
    [$owner, $booking, $attempt] = dokuPaidBooking();
    $service = app(DokuPaymentService::class);
    $service->receive(dokuOutcome($attempt['attempt_guid']));
    $service->receive(dokuOutcome($attempt['attempt_guid'], ['event_key' => 'second-callback', 'payload_hash' => hash('sha256', 'second-callback')]));

    $rows = DB::table('notifications')->where('booking_guid', $booking->guid)->where('type', 'booking.payment_receipt')->get();
    expect($rows)->toHaveCount(1);
    expect($rows[0]->user_guid)->toBe($owner->guid)
        ->and($rows[0]->event_key)->toBe('booking:'.$booking->guid.':payment_receipt:'.$attempt['attempt_guid']);
    expect(DB::table('notifications')->where('booking_guid', $booking->guid)->whereIn('type', ['booking.sandbox_paid', 'booking.doku_paid'])->count())->toBe(0);
    expect(DB::table('notification_deliveries')->where('notification_guid', $rows[0]->guid)->where('channel', 'email')->count())->toBe(1);

    $invoice = DB::table('invoices')->where('booking_guid', $booking->guid)->value('invoice_number');
    $timezone = DB::table('bookings')->where('guid', $booking->guid)->value('destination_timezone_snapshot');
    $paidAt = CarbonImmutable::parse('2026-11-08 08:00:00', 'UTC')->setTimezone($timezone)->format('Y-m-d H:i');
    foreach ([$invoice, DB::table('bookings')->where('guid', $booking->guid)->value('booking_number'), 'Rp 20.000', 'Virtual Account BNI', '****6789',
        'SANDBOX', 'TEST ONLY', $paidAt, $timezone] as $expected) {
        expect($rows[0]->body)->toContain((string) $expected);
    }
    expect($rows[0]->body)->not->toContain('988123456789');
});

it('says when the entry QR becomes available instead of attaching one before H-5', function () {
    [$owner, $booking] = dokuBooking();
    DB::table('destination_quotas')->where('guid', $booking->destination_quota_guid)->update(['visit_date' => now()->addDays(20)->toDateString()]);
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $attempt = dokuStart($owner, $booking);

    app(DokuPaymentService::class)->receive(dokuOutcome($attempt['attempt_guid']));

    $body = DB::table('notifications')->where('booking_guid', $booking->guid)->where('type', 'booking.payment_receipt')->value('body');
    expect($body)->toContain(now()->addDays(15)->toDateString())->and($body)->toContain('QR tiket');
});

it('exposes the receipt only for verified paid or truthfully adjusted payments', function () {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $path = '/api/v1/bookings/'.$booking->guid.'/documents/receipt';
    $attempt = dokuStart($owner, $booking);
    $this->getJson($path, bearer($owner))->assertNotFound();
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/payment-intent', bearer($owner))->assertJsonPath('data.receipt_available', false);
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/documents', bearer($owner))->assertJsonPath('data.receipt', null);

    app(DokuPaymentService::class)->receive(dokuOutcome($attempt['attempt_guid']));
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/payment-intent', bearer($owner))->assertJsonPath('data.receipt_available', true);
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/documents', bearer($owner))->assertJsonPath('data.receipt.download_available', true)
        ->assertJsonPath('data.receipt.status', 'paid');
    dokuCapturePdfHtml($html);

    $response = $this->get($path, bearer($owner))->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('application/pdf')->and($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($html)->toContain(DB::table('invoices')->where('booking_guid', $booking->guid)->value('invoice_number'))
        ->toContain('LUNAS')->toContain('Rp 20.000')->toContain('SANDBOX')->toContain('****6789')->not->toContain('988123456789');
    $this->get($path, bearer(makeUser(0)))->assertNotFound();
    $this->get($path, bearer(makeUser(1)))->assertNotFound();
    $this->get($path, bearer(makeUser(2)))->assertOk();
});

it('shows a reversed payment as an adjustment and never as fresh paid proof', function () {
    [$owner, $booking, $attempt] = dokuPaidBooking();
    app(DokuPaymentService::class)->receive(dokuOutcome($attempt['attempt_guid'], ['status' => 'reversed', 'event_key' => 'reversal', 'payload_hash' => hash('sha256', 'reversal')]));
    dokuCapturePdfHtml($html);

    $this->get('/api/v1/bookings/'.$booking->guid.'/documents/receipt', bearer($owner))->assertOk();

    expect($html)->not->toContain('LUNAS')->toContain('DIKEMBALIKAN ATAU DIBATALKAN')->toContain('Pernah diverifikasi dibayar');
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/documents', bearer($owner))->assertJsonPath('data.receipt.status', 'adjusted');
});

it('withholds the receipt while a payment is unapplied or under review', function () {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $attempt = dokuStart($owner, $booking);
    $this->travelTo(CarbonImmutable::parse('2026-11-08 09:30:00', 'UTC'));
    app(BookingService::class)->expireDue();
    app(DokuPaymentService::class)->receive(dokuOutcome($attempt['attempt_guid'], ['occurred_at' => '2026-11-08T09:10:00+00:00']));

    $this->getJson('/api/v1/bookings/'.$booking->guid.'/documents/receipt', bearer($owner))->assertNotFound();
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/payment-intent', bearer($owner))->assertJsonPath('data.receipt_available', false);
});

function dokuTicketState(string $bookingGuid): array
{
    return ['qr' => DB::table('booking_tickets')->where('booking_guid', $bookingGuid)->whereNotNull('qr_token_hash')->count(),
        'entitlements' => DB::table('booking_tickets')->where('booking_guid', $bookingGuid)->count(),
        'tokens' => DB::table('booking_tickets')->where('booking_guid', $bookingGuid)->orderBy('sequence')->pluck('qr_token_hash')->all(),
        'events' => DB::table('notifications')->where('booking_guid', $bookingGuid)->where('type', 'booking.ticket_issued')->get()];
}

it('issues exactly quantity QR units and one ticket event right after a verified payment inside the H-5 window', function () {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $attempt = dokuStart($owner, $booking);
    $service = app(DokuPaymentService::class);

    $service->receive(dokuOutcome($attempt['attempt_guid']));

    $first = dokuTicketState($booking->guid);
    expect($first['qr'])->toBe(2)->and($first['entitlements'])->toBe(2)->and($first['events'])->toHaveCount(1);
    expect($first['events'][0]->event_key)->toBe('booking:'.$booking->guid.':ticket_issued')->and($first['events'][0]->user_guid)->toBe($owner->guid);
    expect($first['events'][0]->body)->toContain('Jumlah pengunjung: 2')->toContain(DB::table('bookings')->where('guid', $booking->guid)->value('booking_number'))
        ->toContain($booking->destination_name_snapshot)->toContain(substr((string) $booking->visit_date, 0, 10))->toContain('SANDBOX')->not->toContain('WDR1');
    $service->receive(dokuOutcome($attempt['attempt_guid'], ['event_key' => 'again', 'payload_hash' => hash('sha256', 'again')]));
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/tickets', bearer($owner))->assertOk()->assertJsonPath('data.tickets.0.qr_available', true);
    $this->artisan('tickets:issue-qr')->assertSuccessful();
    (new IssueBookingTicketQr($booking->guid))->handle(app(TicketService::class));
    $again = dokuTicketState($booking->guid);
    expect($again['tokens'])->toBe($first['tokens'])->and($again['events'])->toHaveCount(1);
    expect(DB::table('notifications')->where('booking_guid', $booking->guid)->where('type', 'booking.payment_receipt')->count())->toBe(1);
    expect(DB::table('notification_deliveries')->whereIn('notification_guid', $again['events']->pluck('guid'))->where('channel', 'email')->count())->toBe(1);
});

it('puts only an authenticated ticket-page link in the ticket email, never the QR or a token', function (?string $base, bool $expectLink) {
    config(['operations.ticket_link_base_url' => $base]);
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $attempt = dokuStart($owner, $booking);

    app(DokuPaymentService::class)->receive(dokuOutcome($attempt['attempt_guid']));

    $body = dokuTicketState($booking->guid)['events'][0]->body;
    $payload = Crypt::decryptString(DB::table('booking_tickets')->where('booking_guid', $booking->guid)->orderBy('sequence')->value('qr_token_encrypted'));
    expect($body)->not->toContain('WDR1')->not->toContain($payload)->not->toContain('token=')->not->toContain('?');
    $link = 'https://tiket.example.com/tickets/'.$booking->guid;
    $expectLink ? expect($body)->toContain($link) : expect($body)->not->toContain('/tickets/');
    expect(DB::table('notifications')->where('booking_guid', $booking->guid)->where('type', 'booking.payment_receipt')->value('body'))->not->toContain('/tickets/');
})->with([['https://tiket.example.com', true], ['https://tiket.example.com/', true], ['http://tiket.example.com', false], [null, false], ['javascript:alert(1)', false]]);

it('serves a stateless ticket landing page that reveals nothing, while the ticket API stays owner-only', function () {
    config(['operations.ticket_link_base_url' => 'https://tiket.example.com']);
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    app(DokuPaymentService::class)->receive(dokuOutcome(dokuStart($owner, $booking)['attempt_guid']));

    foreach ([$booking->guid, (string) Str::uuid()] as $guid) {
        $page = $this->get('/tickets/'.$guid)->assertOk();
        expect($page->headers->get('Cache-Control'))->toContain('no-store')->and($page->headers->get('X-Robots-Tag'))->toContain('noindex');
        expect($page->getContent())->not->toContain('WDR1')->not->toContain($booking->booking_number ?? 'BK-')->not->toContain($booking->destination_name_snapshot);
    }
    $this->get('/tickets/not-a-uuid')->assertNotFound();
    $path = '/api/v1/bookings/'.$booking->guid.'/tickets';
    $this->getJson($path)->assertUnauthorized();
    $this->getJson($path, bearer(makeUser()))->assertForbidden();
    $this->getJson($path, bearer($owner))->assertOk()->assertJsonPath('data.tickets.0.qr_available', true);
});

it('queues one idempotent issuance job only when the payment lands inside the QR window', function (int $daysAhead, int $expectedJobs) {
    Queue::fake([IssueBookingTicketQr::class]);
    [$owner, $booking] = dokuBooking();
    DB::table('destination_quotas')->where('guid', $booking->destination_quota_guid)->update(['visit_date' => now()->addDays($daysAhead)->toDateString()]);
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $attempt = dokuStart($owner, $booking);

    app(DokuPaymentService::class)->receive(dokuOutcome($attempt['attempt_guid']));

    Queue::assertPushed(IssueBookingTicketQr::class, $expectedJobs);
    expect(dokuTicketState($booking->guid)['qr'])->toBe(0);
})->with([[4, 1], [6, 0]]);

it('keeps entitlements without QR before H-5 and issues at the Asia/Jakarta local midnight, not the UTC one', function () {
    [$owner, $booking] = dokuBooking();
    DB::table('destination_quotas')->where('guid', $booking->destination_quota_guid)->update(['visit_date' => '2026-11-14']);
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $attempt = dokuStart($owner, $booking);

    app(DokuPaymentService::class)->receive(dokuOutcome($attempt['attempt_guid']));

    $paid = dokuTicketState($booking->guid);
    expect($paid['entitlements'])->toBe(2)->and($paid['qr'])->toBe(0)->and($paid['events'])->toHaveCount(0);
    expect(DB::table('notifications')->where('booking_guid', $booking->guid)->where('type', 'booking.payment_receipt')->count())->toBe(1);
    // 2026-11-09 00:00 Asia/Jakarta == 2026-11-08 17:00 UTC: one minute earlier is still H-6 locally although UTC is already Nov 8.
    $this->travelTo(CarbonImmutable::parse('2026-11-08 16:59:00', 'UTC'));
    $this->artisan('tickets:issue-qr')->assertSuccessful();
    expect(dokuTicketState($booking->guid)['qr'])->toBe(0);
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/tickets', bearer($owner))->assertJsonPath('data.ticket_state', 'scheduled');

    $this->travelTo(CarbonImmutable::parse('2026-11-08 17:00:00', 'UTC'));
    $this->artisan('tickets:issue-qr')->assertSuccessful();
    $this->artisan('tickets:issue-qr')->assertSuccessful();

    $issued = dokuTicketState($booking->guid);
    expect($issued['qr'])->toBe(2)->and($issued['events'])->toHaveCount(1)->and(array_unique($issued['tokens']))->toHaveCount(2);
});

it('does not rotate, regenerate or announce tokens for checked-in, revoked or already issued units', function () {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $attempt = dokuStart($owner, $booking);
    app(DokuPaymentService::class)->receive(dokuOutcome($attempt['attempt_guid']));
    $before = DB::table('booking_tickets')->where('booking_guid', $booking->guid)->orderBy('sequence')->get();
    DB::table('booking_tickets')->where('guid', $before[0]->guid)->update(['checked_in_at' => now()]);
    DB::table('booking_tickets')->where('guid', $before[1]->guid)->update(['qr_token_hash' => null, 'qr_token_encrypted' => null, 'qr_revoked_at' => now()]);

    app(TicketService::class)->issue($booking->guid);

    $after = DB::table('booking_tickets')->where('booking_guid', $booking->guid)->orderBy('sequence')->get();
    expect($after[0]->qr_token_hash)->toBe($before[0]->qr_token_hash)->and($after[1]->qr_token_hash)->toBeNull();
    expect(dokuTicketState($booking->guid)['events'])->toHaveCount(1);
});

it('keeps the verified payment and receipt when the queue is down after commit', function () {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $attempt = dokuStart($owner, $booking);
    Queue::extend('broken', fn () => new class extends SyncQueue
    {
        public function push($job, $data = '', $queue = null)
        {
            throw new RuntimeException('queue down');
        }
    });
    config(['queue.connections.broken' => ['driver' => 'broken'], 'queue.default' => 'broken']);

    $event = app(DokuPaymentService::class)->receive(dokuOutcome($attempt['attempt_guid']));
    app(DokuPaymentService::class)->apply($event);

    $this->assertDatabaseHas('bookings', ['guid' => $booking->guid, 'status' => 'confirmed']);
    expect(DB::table('notifications')->where('booking_guid', $booking->guid)->where('type', 'booking.payment_receipt')->count())->toBe(1);
    expect(dokuTicketState($booking->guid)['qr'])->toBe(0);
    config(['queue.default' => 'sync']);
    $this->artisan('tickets:issue-qr')->assertSuccessful();
    expect(dokuTicketState($booking->guid)['qr'])->toBe(2);
});

it('keeps an attempt past its window pending verification, escalates it once and never opens a new attempt or frees quota', function () {
    config(['doku.reconciliation_enabled' => true]);
    [$owner, $booking] = dokuBooking();
    $client = dokuClientFake();
    $client->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $client->shouldReceive('status')->andReturn(['status' => 'unknown', 'reason' => 'provider_status_unavailable']);
    $attempt = dokuStart($owner, $booking);
    $quotaBefore = DB::table('destination_quotas')->where('guid', $booking->destination_quota_guid)->first();
    $path = '/api/v1/bookings/'.$booking->guid.'/payment-intent';
    $this->getJson($path, bearer($owner))->assertJsonPath('data.last_attempt.verification_pending', false)->assertJsonPath('data.last_attempt.escalated', false);

    $this->travel(31)->minutes();
    Log::spy();
    $this->getJson($path, bearer($owner))->assertOk()->assertJsonPath('data.last_attempt.verification_pending', true)
        ->assertJsonPath('data.last_attempt.escalated', false)->assertJsonPath('data.can_create', false)->assertJsonPath('data.instruction.presentation_type', 'unavailable');

    $this->artisan('payments:reconcile-doku')->assertSuccessful();
    $first = DB::table('payment_attempts')->where('guid', $attempt['attempt_guid'])->first();
    expect($first->escalated_at)->not->toBeNull()->and($first->escalation_reason)->not->toBeNull()->and($first->status)->toBeIn(['pending', 'unknown']);
    $this->getJson($path, bearer($owner))->assertJsonPath('data.last_attempt.escalated', true)->assertJsonPath('data.last_attempt.verification_pending', true);

    $this->travel(3)->minutes();
    $this->artisan('payments:reconcile-doku')->assertSuccessful();
    $second = DB::table('payment_attempts')->where('guid', $attempt['attempt_guid'])->first();
    expect($second->escalated_at)->toBe($first->escalated_at);
    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []) => str_contains($message, 'unresolved after its instruction window')
        && ($context['attempt_guid'] ?? null) === $attempt['attempt_guid'])->once();

    // The customer retrying gets the very same attempt; the provider is not asked to create another one.
    $retry = $this->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts', ['method_code' => 'virtual_account', 'channel' => 'bni'],
        bearer($owner) + ['Idempotency-Key' => 'retry-after-expiry'])->assertOk()->assertJsonPath('data.attempt_guid', $attempt['attempt_guid'])
        ->assertJsonPath('data.verification_pending', true)->assertJsonPath('data.instruction.presentation_type', 'unavailable');
    expect($retry->json('data.instruction'))->not->toHaveKey('va_number');
    expect(DB::table('payment_attempts')->where('payment_intent_guid', $first->payment_intent_guid)->count())->toBe(1);

    // Past the booking payment window the unresolved payment still holds the quota and reconciliation keeps rescheduling.
    $this->travel(40)->minutes();
    app(BookingService::class)->expireDue();
    expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBeIn(BookingRules::HELD);
    expect(DB::table('destination_quotas')->where('guid', $booking->destination_quota_guid)->first())->toEqual($quotaBefore);
    $before = (int) DB::table('payment_attempts')->where('guid', $attempt['attempt_guid'])->value('reconcile_count');
    DB::table('payment_attempts')->where('guid', $attempt['attempt_guid'])->update(['next_reconcile_at' => now()->subSecond()]);
    app(DokuPaymentService::class)->reconcile($attempt['attempt_guid']);
    $after = DB::table('payment_attempts')->where('guid', $attempt['attempt_guid'])->first();
    expect((int) $after->reconcile_count)->toBe($before + 1)->and($after->next_reconcile_at)->not->toBeNull()->and($after->status)->toBeIn(['pending', 'unknown']);

    // Safe provider evidence arriving late still settles it normally.
    app(DokuPaymentService::class)->receive(dokuOutcome($attempt['attempt_guid'], ['occurred_at' => CarbonImmutable::parse('2026-11-08 08:20:00', 'UTC')->toIso8601String()]));
    $this->getJson($path, bearer($owner))->assertJsonPath('data.last_attempt.verification_pending', false)->assertJsonPath('data.payment_status', 'paid');
});

it('does not escalate attempts that are inside their window or already terminal', function () {
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $attempt = dokuStart($owner, $booking);

    expect(app(DokuPaymentService::class)->escalateOverdue())->toBe(0);
    app(DokuPaymentService::class)->receive(dokuOutcome($attempt['attempt_guid']));
    $this->travel(31)->minutes();
    expect(app(DokuPaymentService::class)->escalateOverdue())->toBe(0);
    expect(DB::table('payment_attempts')->whereNotNull('escalated_at')->count())->toBe(0);
});

it('never replays a stored VA instruction or can_pay after the attempt passed its window', function () {
    config(['doku.reconciliation_enabled' => true]);
    [$owner, $booking] = dokuBooking();
    dokuClientFake()->shouldReceive('create')->once()->andReturnUsing('dokuCreateResult');
    $attempt = dokuStart($owner, $booking, 'replay-key');
    expect($attempt['instruction']['va_number'])->toBe('988123456789');
    $replay = $this->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts', ['method_code' => 'virtual_account', 'channel' => 'bni'],
        bearer($owner) + ['Idempotency-Key' => 'replay-key'])->assertOk();
    $replay->assertJsonPath('data.instruction.va_number', '988123456789')->assertJsonPath('data.verification_pending', false);

    $this->travel(31)->minutes();
    $this->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts', ['method_code' => 'virtual_account', 'channel' => 'bni'],
        bearer($owner) + ['Idempotency-Key' => 'replay-key'])->assertOk()->assertJsonPath('data.attempt_guid', $attempt['attempt_guid'])
        ->assertJsonPath('data.verification_pending', true)->assertJsonPath('data.instruction.presentation_type', 'unavailable')
        ->assertJsonMissingPath('data.instruction.va_number');
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/payment-intent', bearer($owner))->assertJsonPath('data.can_pay', false)
        ->assertJsonPath('data.reason', 'payment_verification_pending');
});
