<?php

use App\Services\BookingService;
use App\Services\Doku\DokuChannelRegistry;
use App\Services\Doku\DokuClient;
use App\Services\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * QRIS is BLOCKED_EXTERIOR for settlement: no notification contract and no enumerated QRIS query statuses are documented.
 * These tests cover the documented generate call and prove the channel stays unreachable through the public API.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 08:00:00', 'UTC'));
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $this->qrisKey = tempnam(sys_get_temp_dir(), 'doku-qris-');
    file_put_contents($this->qrisKey, $pem);
    config(['operations.demo_payments_enabled' => false, 'app.debug' => false, 'doku.enabled' => true, 'doku.environment' => 'sandbox',
        'doku.client_id' => 'MCH-TEST', 'doku.secret_key' => 'test-only-secret', 'doku.merchant_private_key_path' => $this->qrisKey,
        'doku.merchant_private_key_passphrase' => '', 'doku.partner_service_id' => '', 'doku.customer_prefix' => '',
        'doku.channels' => ['qris'], 'doku.verified_channels' => ['qris'],
        'doku.qris' => ['merchant_id' => 'MERCHANT-FIXTURE', 'terminal_id' => 'TERM01', 'postal_code' => '12345'],
        'doku.webhook_url' => 'https://sandbox.example.com/api/v1/webhooks/doku/va']);
    Http::preventStrayRequests();
});

afterEach(function () {
    unlink($this->qrisKey);
});

function qrisAttempt(): object
{
    return (object) ['guid' => 'qris-attempt', 'provider' => 'doku', 'environment' => 'sandbox', 'method' => 'qris', 'channel' => 'qris',
        'merchant_reference' => 'WDK-qris-reference', 'provider_request_id' => '20261108000000000002', 'amount' => 20000, 'currency' => 'IDR',
        'instruction_expires_at' => now()->addHour()->toDateTimeString()];
}

function qrisBooking(): object
{
    return (object) ['visitor_name' => 'Test Visitor', 'payment_expires_at' => now()->addHour()->toDateTimeString()];
}

function qrisFake(array|Closure $generate): void
{
    $client = Mockery::mock(DokuClient::class)->makePartial();
    $client->shouldReceive('expiryWindowReason')->andReturn(null);
    app()->instance(DokuClient::class, $client);
    Http::fake([
        'https://api-sandbox.doku.com/authorization/v1/access-token/b2b' => Http::response(['responseCode' => '2007300', 'accessToken' => 'test-token', 'tokenType' => 'Bearer', 'expiresIn' => 900]),
        'https://api-sandbox.doku.com/snap-adapter/b2b/v1.0/qr/qr-mpm-generate' => is_array($generate) ? Http::response($generate) : $generate,
    ]);
}

function qrisSuccess(array $override = []): array
{
    return array_replace(['responseCode' => '2004700', 'responseMessage' => 'Successful', 'referenceNo' => 'QREF-1', 'partnerReferenceNo' => 'WDK-qris-reference',
        'qrContent' => '00020101021226FIXTURE', 'terminalId' => 'TERM01'], $override);
}

it('generates a QR with the documented request and normalizes the payload', function () {
    qrisFake(qrisSuccess());

    $result = app(DokuClient::class)->create(qrisAttempt(), qrisBooking());

    expect($result)->toMatchArray(['status' => 'pending', 'provider_reference' => 'QREF-1', 'presentation_type' => 'qr_payload'])
        ->and($result['instruction'])->toBe(['presentation_type' => 'qr_payload', 'qr_content' => '00020101021226FIXTURE', 'copyable' => false]);
    Http::assertSent(fn (ClientRequest $request) => ! str_ends_with($request->url(), '/qr-mpm-generate') || (
        $request['partnerReferenceNo'] === 'WDK-qris-reference' && $request['amount'] === ['value' => '20000.00', 'currency' => 'IDR']
        && $request['merchantId'] === 'MERCHANT-FIXTURE' && $request['terminalId'] === 'TERM01'
        && $request['additionalInfo'] === ['postalCode' => '12345', 'feeType' => '1']
        && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+07:00$/', $request['validityPeriod']) === 1
        && $request->header('CHANNEL-ID')[0] === 'H2H' && $request->header('X-EXTERNAL-ID')[0] === '20261108000000000002'));
});

it('does not send a QRIS generate while the merchant expiry window contract is unverified', function () {
    Http::fake();

    expect((new DokuClient)->create(qrisAttempt(), qrisBooking()))
        ->toBe(['status' => 'unknown', 'reason' => 'provider_expiry_window_unverified']);
    Http::assertNothingSent();
});

it('keeps a timed-out generate unresolved', function () {
    qrisFake(fn () => throw new ConnectionException('timeout'));

    expect(app(DokuClient::class)->create(qrisAttempt(), qrisBooking()))->toBe(['status' => 'unknown', 'reason' => 'provider_timeout']);
});

it('refuses an invalid or mismatched generate response', function (array $override) {
    qrisFake(qrisSuccess($override));

    expect(app(DokuClient::class)->create(qrisAttempt(), qrisBooking())['status'])->toBe('unknown');
})->with([
    'provider error' => [['responseCode' => '4004700']],
    'empty payload' => [['qrContent' => '']],
    'control characters' => [['qrContent' => "0002\x00bad"]],
    'oversized payload' => [['qrContent' => str_repeat('A', 2049)]],
    'other order' => [['partnerReferenceNo' => 'someone-else']],
    'missing reference' => [['referenceNo' => '']],
    'other terminal' => [['terminalId' => 'OTHER']],
]);

it('requires merchant id terminal id and postal code from configuration', function (string $key, string $value) {
    config([$key => $value]);
    Http::fake();

    expect(app(DokuClient::class)->create(qrisAttempt(), qrisBooking()))->toBe(['status' => 'unknown', 'reason' => 'doku_not_configured']);
    Http::assertNothingSent();
})->with([['doku.qris.merchant_id', ''], ['doku.qris.terminal_id', 'a'], ['doku.qris.postal_code', 'ABCDE']]);

it('cannot map a QRIS status without an enumerated contract', function () {
    Http::fake();

    expect(app(DokuClient::class)->status(qrisAttempt()))->toBe(['status' => 'unknown', 'reason' => 'provider_terminal_contract_unverified']);
    Http::assertNothingSent();
});

it('stays unavailable through the public API because no paid signal is verifiable', function () {
    [$destination, $date] = seedDestination(capacity: 5, price: 10000, days: 5);
    $owner = makeUser();
    $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date, 2) + ['idempotency_key' => (string) Str::uuid()]);
    $booking = app(BookingService::class)->decide(makeUser(2), $booking->guid, true);
    $booking = app(TicketService::class)->booking($booking->guid);

    expect(app(DokuChannelRegistry::class)->reason('qris'))->toBe('channel_contract_unverified');
    $this->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts', ['method_code' => 'qris', 'channel' => 'qris'],
        bearer($owner) + ['Idempotency-Key' => 'qris-blocked'])->assertStatus(409)->assertJsonPath('code', 'channel_contract_unverified');
    $this->assertDatabaseCount('payment_attempts', 0);
    Http::assertNothingSent();
});
