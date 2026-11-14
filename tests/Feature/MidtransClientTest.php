<?php

use App\Services\Midtrans\MidtransChannelRegistry;
use App\Services\Midtrans\MidtransClient;
use App\Services\Midtrans\MidtransStatusMapper;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * Midtrans Core API (standard /v2/charge) transport and fail-closed configuration. Nothing here talks to the real provider:
 * Http::fake serves fixtures and Http::preventStrayRequests() turns any unmocked call into a failure.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 08:00:00', 'UTC'));
    midtransConfig();
    Http::preventStrayRequests();
});

function midtransConfig(array $override = []): void
{
    config(['midtrans' => array_replace_recursive([
        'enabled' => true, 'environment' => 'sandbox', 'server_key' => 'SB-Mid-server-FIXTURE0000000000', 'merchant_id' => 'G000000001',
        'channels' => ['va_bca', 'va_bni', 'qris'], 'verified_channels' => [], 'production_approved' => false,
        'base_urls' => ['sandbox' => 'https://api.sandbox.midtrans.com', 'production' => 'https://api.midtrans.com'],
        'http_timeout_seconds' => 10, 'minimum_expiry_seconds' => 30, 'qris_acquirer' => 'gopay',
    ], $override)]);
}

/** Booking approved at 08:00 UTC (15:00 WIB) with an absolute deadline 60 minutes later. */
function midtransAttempt(string $method = 'virtual_account', string $channel = 'bca', array $override = []): object
{
    return (object) array_replace([
        'guid' => '11111111-1111-4111-8111-111111111111', 'provider' => 'midtrans', 'environment' => 'sandbox', 'method' => $method, 'channel' => $channel,
        'merchant_reference' => 'PAMI-11111111-1111-4111-8111-111111111111', 'provider_request_id' => 'req-1', 'amount' => 20000, 'currency' => 'IDR',
        'instruction_expires_at' => CarbonImmutable::parse('2026-11-08 09:00:00', 'UTC')->toDateTimeString(),
        'started_at' => CarbonImmutable::parse('2026-11-08 08:00:00', 'UTC')->toDateTimeString(),
    ], $override);
}

function midtransBooking(): object
{
    return (object) ['guid' => 'booking-1', 'payment_expires_at' => CarbonImmutable::parse('2026-11-08 09:00:00', 'UTC')->toDateTimeString(),
        'payment_approved_at' => CarbonImmutable::parse('2026-11-08 08:00:00', 'UTC')];
}

function midtransVaResponse(string $bank = 'bca', array $override = []): array
{
    return array_replace([
        'status_code' => '201', 'status_message' => 'Success, Bank Transfer transaction is created', 'transaction_id' => 'trx-bca-0001',
        'order_id' => 'PAMI-11111111-1111-4111-8111-111111111111', 'merchant_id' => 'G000000001', 'gross_amount' => '20000.00',
        'currency' => 'IDR', 'payment_type' => 'bank_transfer', 'transaction_time' => '2026-11-08 15:00:00', 'transaction_status' => 'pending',
        'va_numbers' => [['bank' => $bank, 'va_number' => '81234500001']], 'fraud_status' => 'accept', 'expiry_time' => '2026-11-08 16:00:00',
    ], $override);
}

function midtransQrisResponse(array $override = []): array
{
    return array_replace([
        'status_code' => '201', 'status_message' => 'QRIS transaction is created', 'transaction_id' => 'trx-qris-0001',
        'order_id' => 'PAMI-11111111-1111-4111-8111-111111111111', 'merchant_id' => 'G000000001', 'gross_amount' => '20000.00',
        'currency' => 'IDR', 'payment_type' => 'qris', 'transaction_time' => '2026-11-08 15:00:00', 'transaction_status' => 'pending',
        'fraud_status' => 'accept', 'acquirer' => 'gopay', 'qr_string' => '00020101021226FIXTURE', 'expiry_time' => '2026-11-08 16:00:00',
        'actions' => [['name' => 'generate-qr-code', 'method' => 'GET', 'url' => 'https://api.sandbox.midtrans.com/v2/qris/trx-qris-0001/qr-code']],
    ], $override);
}

it('is ready only when every configuration gate passes and reports stable reasons', function (array $override, string $code, ?string $reason) {
    midtransConfig($override);

    expect(app(MidtransChannelRegistry::class)->reason($code))->toBe($reason);
})->with([
    'sandbox ready' => [[], 'va_bca', null],
    'sandbox ready for bni' => [[], 'va_bni', null],
    'sandbox ready for qris' => [[], 'qris', null],
    'kill switch' => [['enabled' => false], 'va_bca', 'midtrans_disabled'],
    'missing key' => [['server_key' => ''], 'va_bca', 'midtrans_not_configured'],
    'placeholder key' => [['server_key' => 'ISI_SERVER_KEY_SANDBOX_LO'], 'va_bca', 'midtrans_not_configured'],
    'production-looking key in sandbox' => [['server_key' => 'Mid-server-PRODUCTIONKEY0000'], 'va_bca', 'midtrans_not_configured'],
    'unknown environment' => [['environment' => 'staging'], 'va_bca', 'midtrans_not_configured'],
    'sandbox key in production' => [['environment' => 'production', 'production_approved' => true, 'verified_channels' => ['va_bca']], 'va_bca', 'midtrans_not_configured'],
    'production without merchant identity' => [['environment' => 'production', 'server_key' => 'Mid-server-PRODUCTIONKEY0000', 'merchant_id' => '',
        'production_approved' => true, 'verified_channels' => ['va_bca']], 'va_bca', 'midtrans_not_configured'],
    'production without approval' => [['environment' => 'production', 'server_key' => 'Mid-server-PRODUCTIONKEY0000',
        'verified_channels' => ['va_bca']], 'va_bca', 'midtrans_production_not_approved'],
    'production unverified channel' => [['environment' => 'production', 'server_key' => 'Mid-server-PRODUCTIONKEY0000',
        'production_approved' => true, 'verified_channels' => ['va_bca']], 'va_bni', 'channel_not_verified'],
    'production fully approved' => [['environment' => 'production', 'server_key' => 'Mid-server-PRODUCTIONKEY0000',
        'production_approved' => true, 'verified_channels' => ['va_bca']], 'va_bca', null],
    'wrong production host' => [['environment' => 'production', 'server_key' => 'Mid-server-PRODUCTIONKEY0000', 'production_approved' => true,
        'verified_channels' => ['va_bca'], 'base_urls' => ['production' => 'https://api.sandbox.midtrans.com']], 'va_bca', 'midtrans_not_configured'],
    'insecure host' => [['base_urls' => ['sandbox' => 'http://api.sandbox.midtrans.com']], 'va_bca', 'midtrans_not_configured'],
    'channel outside allowlist' => [['channels' => ['va_bni']], 'va_bca', 'channel_not_enabled'],
    'unknown channel' => [[], 'va_permata', 'channel_unavailable'],
]);

it('lists only the three release channels and maps each to a native presentation', function () {
    $registry = app(MidtransChannelRegistry::class);

    expect(array_keys($registry->all()))->toBe(['va_bca', 'va_bni', 'qris'])
        ->and($registry->find('virtual_account', 'bca'))->toMatchArray(['code' => 'va_bca', 'presentation_type' => 'virtual_account'])
        ->and($registry->find('qris', 'qris'))->toMatchArray(['code' => 'qris', 'presentation_type' => 'qr_image'])
        ->and($registry->find('ewallet', 'dana'))->toBeNull()
        ->and($registry->find('virtual_account', 'permata'))->toBeNull();
});

it('creates a BCA virtual account with the documented Core API request', function () {
    Http::fake(['https://api.sandbox.midtrans.com/v2/charge' => Http::response(midtransVaResponse())]);

    $result = app(MidtransClient::class)->charge(midtransAttempt(), midtransBooking());

    expect($result)->toMatchArray(['status' => 'pending', 'provider_reference' => 'trx-bca-0001', 'presentation_type' => 'virtual_account',
        'expires_at' => '2026-11-08T09:00:00+00:00'])
        ->and($result['instruction'])->toBe(['presentation_type' => 'virtual_account', 'bank_code' => 'bca', 'va_number' => '81234500001', 'copyable' => true]);
    Http::assertSentCount(1);
    Http::assertSent(function (ClientRequest $request) {
        return $request->method() === 'POST' && $request->url() === 'https://api.sandbox.midtrans.com/v2/charge'
            && $request->header('Authorization')[0] === 'Basic '.base64_encode('SB-Mid-server-FIXTURE0000000000:')
            && $request['payment_type'] === 'bank_transfer' && $request['bank_transfer'] === ['bank' => 'bca']
            && $request['transaction_details'] === ['order_id' => 'PAMI-11111111-1111-4111-8111-111111111111', 'gross_amount' => 20000]
            && is_int($request['transaction_details']['gross_amount'])
            && $request['custom_expiry'] === ['order_time' => '2026-11-08 15:00:00 +0700', 'expiry_duration' => 60, 'unit' => 'minute']
            && ! array_key_exists('qris', $request->data()) && ! array_key_exists('customer_details', $request->data());
    });
});

it('creates a BNI virtual account', function () {
    Http::fake(['*' => Http::response(midtransVaResponse('bni', ['va_numbers' => [['bank' => 'bni', 'va_number' => '9881234500001']]]))]);

    $result = app(MidtransClient::class)->charge(midtransAttempt('virtual_account', 'bni'), midtransBooking());

    expect($result['status'])->toBe('pending')
        ->and($result['instruction'])->toBe(['presentation_type' => 'virtual_account', 'bank_code' => 'bni', 'va_number' => '9881234500001', 'copyable' => true]);
    Http::assertSent(fn (ClientRequest $request) => $request['bank_transfer'] === ['bank' => 'bni']);
});

it('creates a QRIS charge whose instruction never exposes the provider URL or payload', function () {
    Http::fake(['*' => Http::response(midtransQrisResponse())]);

    $result = app(MidtransClient::class)->charge(midtransAttempt('qris', 'qris'), midtransBooking());

    expect($result)->toMatchArray(['status' => 'pending', 'provider_reference' => 'trx-qris-0001', 'presentation_type' => 'qr_image'])
        ->and($result['instruction'])->toBe(['presentation_type' => 'qr_image', 'acquirer' => 'gopay', 'image_available' => true,
            'image_content_type' => 'image/png', '_provider' => ['image_url' => 'https://api.sandbox.midtrans.com/v2/qris/trx-qris-0001/qr-code']]);
    Http::assertSent(fn (ClientRequest $request) => $request['payment_type'] === 'qris' && $request['qris'] === ['acquirer' => 'gopay']
        && ! array_key_exists('bank_transfer', $request->data()));
});

it('truncates the provider expiry to whole minutes so it can never outlive the booking deadline', function () {
    Http::fake(['*' => Http::response(midtransVaResponse('bca', ['expiry_time' => '2026-11-08 15:30:00']))]);
    $attempt = midtransAttempt(override: ['instruction_expires_at' => '2026-11-08 08:30:45']);

    app(MidtransClient::class)->charge($attempt, midtransBooking());

    Http::assertSent(fn (ClientRequest $request) => $request['custom_expiry']['expiry_duration'] === 30 && $request['custom_expiry']['unit'] === 'minute');
});

it('refuses to call the provider when the remaining window is below the safe minimum', function () {
    Http::fake();
    $attempt = midtransAttempt(override: ['instruction_expires_at' => now()->addSeconds(30)->toDateTimeString()]);

    expect(app(MidtransClient::class)->expiryWindowReason(CarbonImmutable::parse($attempt->instruction_expires_at, 'UTC')))->toBe('payment_window_closed');
    expect(app(MidtransClient::class)->expiryWindowReason(now()->addSeconds(31)->toImmutable()))->toBeNull();
    $result = app(MidtransClient::class)->charge($attempt, midtransBooking());

    expect($result)->toMatchArray(['status' => 'failed', 'reason' => 'payment_window_closed']);
    Http::assertNothingSent();
});

it('treats every response that does not match the reserved attempt as unknown, never as an instruction', function (array $override) {
    Http::fake(['*' => Http::response(midtransVaResponse('bca', $override))]);

    $result = app(MidtransClient::class)->charge(midtransAttempt(), midtransBooking());

    expect($result['status'])->toBe('unknown')->and($result)->not->toHaveKey('instruction');
})->with([
    'another order' => [['order_id' => 'PAMI-other']],
    'another amount' => [['gross_amount' => '19999.00']],
    'fractional amount' => [['gross_amount' => '20000.50']],
    'another currency' => [['currency' => 'USD']],
    'another bank' => [['va_numbers' => [['bank' => 'bni', 'va_number' => '81234500001']]]],
    'another payment type' => [['payment_type' => 'qris']],
    'another merchant' => [['merchant_id' => 'G999999999']],
    'missing va number' => [['va_numbers' => []]],
    'non numeric va number' => [['va_numbers' => [['bank' => 'bca', 'va_number' => 'ABC-1']]]],
    'missing transaction id' => [['transaction_id' => '']],
    'expiry beyond deadline' => [['expiry_time' => '2026-11-08 16:00:01']],
    'expiry missing' => [['expiry_time' => null]],
    'already expired' => [['expiry_time' => '2026-11-08 14:59:59']],
    'not pending' => [['transaction_status' => 'settlement']],
    'unexpected status code' => [['status_code' => '200']],
]);

it('rejects QRIS image actions that are not pinned to the Midtrans host for this transaction', function (array $actions) {
    Http::fake(['*' => Http::response(midtransQrisResponse(['actions' => $actions]))]);

    $result = app(MidtransClient::class)->charge(midtransAttempt('qris', 'qris'), midtransBooking());

    expect($result['status'])->toBe('unknown')->and($result)->not->toHaveKey('instruction');
})->with([
    'foreign host' => [[['name' => 'generate-qr-code', 'method' => 'GET', 'url' => 'https://evil.example/v2/qris/trx-qris-0001/qr-code']]],
    'plain http' => [[['name' => 'generate-qr-code', 'method' => 'GET', 'url' => 'http://api.sandbox.midtrans.com/v2/qris/trx-qris-0001/qr-code']]],
    'production host in sandbox' => [[['name' => 'generate-qr-code', 'method' => 'GET', 'url' => 'https://api.midtrans.com/v2/qris/trx-qris-0001/qr-code']]],
    'another transaction' => [[['name' => 'generate-qr-code', 'method' => 'GET', 'url' => 'https://api.sandbox.midtrans.com/v2/qris/other/qr-code']]],
    'credentials in url' => [[['name' => 'generate-qr-code', 'method' => 'GET', 'url' => 'https://user:pw@api.sandbox.midtrans.com/v2/qris/trx-qris-0001/qr-code']]],
    'query string' => [[['name' => 'generate-qr-code', 'method' => 'GET', 'url' => 'https://api.sandbox.midtrans.com/v2/qris/trx-qris-0001/qr-code?x=1']]],
    'no image action' => [[['name' => 'get-status', 'method' => 'GET', 'url' => 'https://api.sandbox.midtrans.com/v2/trx-qris-0001/status']]],
]);

it('classifies provider failures: definitive rejections fail, ambiguous outcomes stay unknown', function (callable $response, string $status, string $reason) {
    Http::fake(['*' => $response]);

    $result = app(MidtransClient::class)->charge(midtransAttempt(), midtransBooking());

    expect($result)->toMatchArray(['status' => $status, 'reason' => $reason]);
    Http::assertSentCount(1); // Never an automatic Charge retry.
})->with([
    'channel not activated' => [fn () => Http::response(['status_code' => '402', 'status_message' => 'Payment channel is not activated.']), 'failed', 'provider_rejected'],
    'validation error' => [fn () => Http::response(['status_code' => '400', 'status_message' => 'invalid']), 'failed', 'provider_rejected'],
    'http 401' => [fn () => Http::response(['status_code' => '401'], 401), 'failed', 'provider_unauthorized'],
    'duplicate order id' => [fn () => Http::response(['status_code' => '406', 'status_message' => 'duplicate']), 'unknown', 'provider_unavailable'],
    'server error body' => [fn () => Http::response(['status_code' => '500'], 200), 'unknown', 'provider_unavailable'],
    'http 500' => [fn () => Http::response('upstream', 500), 'unknown', 'provider_unavailable'],
    'rate limited' => [fn () => Http::response('slow down', 429), 'unknown', 'provider_unavailable'],
    'not json' => [fn () => Http::response('<html>', 200), 'unknown', 'provider_unavailable'],
]);

it('leaves a timed out Charge unknown and never sends it a second time', function () {
    $calls = 0;
    Http::fake(['*' => function () use (&$calls) {
        $calls++;

        throw new ConnectionException('cURL error 28: timed out');
    }]);

    $result = app(MidtransClient::class)->charge(midtransAttempt(), midtransBooking());

    expect($result)->toMatchArray(['status' => 'unknown', 'reason' => 'provider_unavailable'])->and($calls)->toBe(1);
});

it('never writes the server key or the provider body to the log', function () {
    Log::spy();
    Http::fake(['*' => Http::response(['status_code' => '402', 'status_message' => 'SENSITIVE-PROVIDER-TEXT SB-Mid-server-FIXTURE0000000000'])]);

    app(MidtransClient::class)->charge(midtransAttempt(), midtransBooking());

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []) {
        $encoded = $message.json_encode($context);

        return ! str_contains($encoded, 'SB-Mid-server') && ! str_contains($encoded, 'SENSITIVE-PROVIDER-TEXT') && ! str_contains($encoded, 'FIXTURE0000');
    })->atLeast()->once();
});

it('does not send any request when the configuration is not ready', function () {
    midtransConfig(['server_key' => '']);
    Http::fake();

    $result = app(MidtransClient::class)->charge(midtransAttempt(), midtransBooking());

    expect($result)->toMatchArray(['status' => 'failed', 'reason' => 'midtrans_not_configured']);
    Http::assertNothingSent();
});

it('normalizes an authenticated status response only when it matches the stored order', function (array $override, string $expected, ?string $reason) {
    $response = midtransVaResponse('bca', array_replace(['status_code' => '200', 'transaction_status' => 'settlement', 'settlement_time' => '2026-11-08 15:05:00'], $override));

    $outcome = app(MidtransStatusMapper::class)->normalize($response, midtransAttempt(), 'status');

    expect($outcome['status'])->toBe($expected)->and($outcome['reason'] ?? null)->toBe($reason);
    if ($expected === 'paid') {
        expect($outcome)->toMatchArray(['merchant_reference' => 'PAMI-11111111-1111-4111-8111-111111111111', 'provider_reference' => 'trx-bca-0001',
            'amount' => 20000, 'currency' => 'IDR', 'method' => 'virtual_account', 'channel' => 'bca', 'occurred_at' => '2026-11-08T08:05:00+00:00']);
    }
})->with([
    'settlement with accepted fraud status' => [[], 'paid', null],
    'settlement without fraud status' => [['fraud_status' => null], 'paid', null],
    'settlement with challenged fraud status' => [['fraud_status' => 'challenge'], 'unknown', 'fraud_status_review'],
    'settlement without time' => [['settlement_time' => null], 'unknown', 'provider_response_invalid'],
    'settlement with another amount' => [['gross_amount' => '10000.00'], 'unknown', 'provider_response_invalid'],
    'settlement for another order' => [['order_id' => 'PAMI-other'], 'unknown', 'provider_response_invalid'],
    'pending' => [['transaction_status' => 'pending'], 'pending', null],
    'expire' => [['transaction_status' => 'expire'], 'expired', 'provider_expire'],
    'deny' => [['transaction_status' => 'deny'], 'failed', 'provider_deny'],
    'cancel' => [['transaction_status' => 'cancel'], 'failed', 'provider_cancel'],
    'full refund' => [['transaction_status' => 'refund', 'refunds' => [['refund_amount' => '20000.00', 'created_at' => '2026-11-08 15:10:00']]], 'refunded', null],
    'refund without evidence' => [['transaction_status' => 'refund'], 'unknown', 'provider_response_invalid'],
    'refund below the paid amount' => [['transaction_status' => 'refund', 'refunds' => [['refund_amount' => '5000.00', 'created_at' => '2026-11-08 15:10:00']]],
        'unknown', 'partial_adjustment_review'],
    'partial refund is not an automatic outcome' => [['transaction_status' => 'partial_refund'], 'unknown', 'partial_adjustment_review'],
    'chargeback is not an automatic outcome' => [['transaction_status' => 'chargeback'], 'unknown', 'partial_adjustment_review'],
    'unrecognised status' => [['transaction_status' => 'authorize'], 'unknown', 'provider_response_invalid'],
]);
