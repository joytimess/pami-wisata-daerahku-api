<?php

use App\Services\Midtrans\MidtransChannelRegistry;
use App\Services\Midtrans\MidtransClient;
use App\Services\Midtrans\MidtransStatusMapper;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/Support/MidtransFixtures.php';

/*
 * Authenticated GET /v2/{stored_order_id}/status and the mapper that turns it into evidence. Fake provider only; no DB.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 08:05:00', 'UTC'));
    mtxConfig();
    Http::preventStrayRequests();
});

function mtxStatusAttempt(string $method = 'virtual_account', string $channel = 'bca', array $override = []): object
{
    return (object) array_replace([
        'guid' => '11111111-1111-4111-8111-111111111111', 'provider' => 'midtrans', 'environment' => 'sandbox', 'method' => $method, 'channel' => $channel,
        'merchant_reference' => 'PAMI-11111111-1111-4111-8111-111111111111', 'merchant_id' => 'G000000001', 'provider_reference' => 'trx-bca-0001',
        'amount' => 20000, 'currency' => 'IDR', 'status' => 'pending',
        'started_at' => '2026-11-08 08:00:00', 'instruction_expires_at' => '2026-11-08 08:15:00',
    ], $override);
}

it('looks up the stored order with Basic Auth, without a body and without following redirects', function () {
    $attempt = mtxStatusAttempt();
    $calls = mtxFakeStatus(mtxStatusBody($attempt, 'pending'));

    $outcome = app(MidtransClient::class)->status($attempt);

    expect($outcome['status'])->toBe('pending')->and($calls->calls)->toBe(1)
        ->and($calls->urls[0])->toBe('https://api.sandbox.midtrans.com/v2/PAMI-11111111-1111-4111-8111-111111111111/status');
    Http::assertSent(fn (ClientRequest $request) => $request->method() === 'GET'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('SB-Mid-server-FIXTURE0000000000:'))
        && $request->body() === '');
});

it('maps a verified settlement to paid evidence bound to the stored attempt, with a raw payload hash', function (string $method, string $channel) {
    $attempt = mtxStatusAttempt($method, $channel);
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement'));

    $outcome = app(MidtransClient::class)->status($attempt);

    expect($outcome)->toMatchArray(['status' => 'paid', 'merchant_reference' => $attempt->merchant_reference, 'provider_reference' => 'trx-bca-0001',
        'amount' => 20000, 'currency' => 'IDR', 'method' => $method, 'channel' => $channel, 'occurred_at' => '2026-11-08T08:05:00+00:00'])
        ->and($outcome['payload_hash'])->toMatch('/^[a-f0-9]{64}$/D');
})->with([['virtual_account', 'bca'], ['virtual_account', 'bni'], ['qris', 'qris']]);

it('maps every other provider status without ever inventing a payment', function (string $state, string $status) {
    $attempt = mtxStatusAttempt();
    mtxFakeStatus(mtxStatusBody($attempt, $state));

    expect(app(MidtransClient::class)->status($attempt)['status'])->toBe($status);
})->with([['pending', 'pending'], ['expire', 'expired'], ['deny', 'failed'], ['cancel', 'failed'], ['refund', 'refunded'],
    ['partial_refund', 'unknown'], ['chargeback', 'unknown']]);

it('works while the new-charge kill switch is off, because existing orders must stay verifiable', function () {
    mtxConfig(['enabled' => false]);
    $attempt = mtxStatusAttempt();
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement'));

    expect(app(MidtransClient::class)->status($attempt)['status'])->toBe('paid');
    expect(app(MidtransChannelRegistry::class)->reason('va_bca'))->toBe('midtrans_disabled');
});

it('fails closed without any request when credentials, environment, merchant or provider do not match the attempt binding', function (array $config, array $attemptOverride, string $reason) {
    mtxConfig($config);
    $attempt = mtxStatusAttempt('virtual_account', 'bca', $attemptOverride);
    Http::fake();

    $outcome = app(MidtransClient::class)->status($attempt);

    expect($outcome)->toBe(['status' => 'unknown', 'reason' => $reason]);
    Http::assertNothingSent();
})->with([
    'no server key' => [['server_key' => ''], [], 'midtrans_not_configured'],
    'sandbox key against production config' => [['environment' => 'production', 'merchant_id' => 'G000000001'], [], 'midtrans_not_configured'],
    'attempt bound to production while sandbox is configured' => [[], ['environment' => 'production'], 'environment_mismatch'],
    'attempt bound to another merchant' => [[], ['merchant_id' => 'G999999999'], 'attempt_merchant_mismatch'],
    'another provider' => [[], ['provider' => 'doku'], 'provider_mismatch'],
]);

it('rejects any response whose identity differs from the stored attempt', function (array $override) {
    $attempt = mtxStatusAttempt();
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement', $override));

    $outcome = app(MidtransClient::class)->status($attempt);

    expect($outcome['status'])->toBe('unknown')->and($outcome)->not->toHaveKey('occurred_at');
})->with([
    'other order id' => [['order_id' => 'PAMI-other']],
    'other transaction id than the bound one' => [['transaction_id' => 'trx-another']],
    'missing transaction id' => [['transaction_id' => '']],
    'lower amount' => [['gross_amount' => '10000.00']],
    'fractional amount' => [['gross_amount' => '20000.50']],
    'other currency' => [['currency' => 'USD']],
    'other bank' => [['va_numbers' => [['bank' => 'bni', 'va_number' => '81234500001']]]],
    'other payment type' => [['payment_type' => 'gopay']],
    'other merchant' => [['merchant_id' => 'G999999999']],
    'challenged fraud status' => [['fraud_status' => 'challenge']],
    'denied fraud status' => [['fraud_status' => 'deny']],
    'settlement without settlement time' => [['settlement_time' => null]],
    'settlement with a pending status code' => [['status_code' => '201']],
]);

it('keeps the specific review reasons for ambiguous provider adjustments', function (string $state, string $reason) {
    $attempt = mtxStatusAttempt();
    mtxFakeStatus(mtxStatusBody($attempt, $state));

    expect(app(MidtransClient::class)->status($attempt))->toBe(['status' => 'unknown', 'reason' => $reason]);
})->with([['partial_refund', 'partial_adjustment_review'], ['chargeback', 'partial_adjustment_review']]);

it('treats provider failures as unknown with a classified reason and never as paid', function (mixed $body, int $http, string $reason) {
    $attempt = mtxStatusAttempt();
    mtxFakeStatus($body, $http);

    expect(app(MidtransClient::class)->status($attempt))->toBe(['status' => 'unknown', 'reason' => $reason]);
})->with([
    'server error' => [['status_code' => '500', 'status_message' => 'boom'], 500, 'provider_unavailable'],
    'rate limited' => [[], 429, 'provider_unavailable'],
    'bad gateway' => [[], 502, 'provider_unavailable'],
    'unauthorized' => [['status_code' => '401'], 401, 'provider_unauthorized'],
    'timeout' => [fn () => new ConnectionException('cURL error 28'), 200, 'provider_unavailable'],
    'order unknown to the provider' => [['status_code' => '404', 'status_message' => "Transaction doesn't exist."], 200, 'provider_order_not_found'],
    'unauthorized in body' => [['status_code' => '401', 'status_message' => 'Unauthorized'], 200, 'provider_unauthorized'],
    'not an object' => [fn () => 'plain', 200, 'provider_response_invalid'],
]);

it('refuses a notification as evidence because it is only a trigger', function () {
    $attempt = mtxStatusAttempt();

    $outcome = app(MidtransStatusMapper::class)->normalize(mtxStatusBody($attempt, 'settlement'), $attempt, 'notification');

    expect($outcome)->toBe(['status' => 'unknown', 'reason' => 'evidence_source_invalid']);
});

it('does not keep a pending order whose provider expiry is later than the booking deadline', function () {
    $attempt = mtxStatusAttempt();
    mtxFakeStatus(mtxStatusBody($attempt, 'pending', ['expiry_time' => '2026-11-08 16:30:00']));

    expect(app(MidtransClient::class)->status($attempt))->toBe(['status' => 'unknown', 'reason' => 'provider_expiry_invalid']);
});

it('recovers a VA instruction from a pending status only while it is still payable', function () {
    $attempt = mtxStatusAttempt();
    mtxFakeStatus(mtxStatusBody($attempt, 'pending'));

    $outcome = app(MidtransClient::class)->status($attempt);

    expect($outcome['status'])->toBe('pending')->and($outcome['presentation_type'])->toBe('virtual_account')
        ->and($outcome['instruction'])->toBe(['presentation_type' => 'virtual_account', 'bank_code' => 'bca', 'va_number' => '81234500001', 'copyable' => true])
        ->and($outcome['expires_at'])->toBe('2026-11-08T08:15:00+00:00');
});

it('never logs the server key, the provider message or the response body for a failed lookup', function () {
    $attempt = mtxStatusAttempt();
    Log::spy();
    mtxFakeStatus(['status_code' => '500', 'status_message' => 'SECRET-PROVIDER-TEXT va_number 81234500001'], 500);

    app(MidtransClient::class)->status($attempt);

    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) {
        $encoded = json_encode($context);

        return str_contains($message, 'Status') && ! str_contains($encoded, 'SB-Mid-server') && ! str_contains($encoded, 'SECRET-PROVIDER-TEXT')
            && ! str_contains($encoded, '81234500001') && ($context['diagnostic_code'] ?? null) === 'midtrans_http_error';
    });
});
