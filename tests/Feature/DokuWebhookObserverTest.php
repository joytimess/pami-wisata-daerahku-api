<?php

beforeEach(function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $this->observerKey = tempnam(sys_get_temp_dir(), 'doku-obs-key-');
    file_put_contents($this->observerKey, $pem);
    $this->observerLog = tempnam(sys_get_temp_dir(), 'doku-obs-log-');
    $this->observerMarker = tempnam(sys_get_temp_dir(), 'doku-obs-marker-');
    @unlink($this->observerMarker);
    config(['doku.webhook_observe_marker' => $this->observerMarker, 'app.debug' => false, 'doku.enabled' => true, 'doku.environment' => 'sandbox', 'doku.client_id' => 'MCH-TEST', 'doku.secret_key' => 'test-only-secret',
        'doku.merchant_private_key_path' => $this->observerKey, 'doku.merchant_private_key_passphrase' => '', 'doku.partner_service_id' => '98829172', 'doku.customer_prefix' => '3',
        'doku.channels' => ['va_bni', 'va_bca'], 'doku.verified_channels' => ['va_bca'], 'doku.webhook_url' => 'https://sandbox.example.com/api/v1/webhooks/doku/va',
        'doku.va_banks.bca' => ['partner_service_id' => '19008', 'customer_prefix' => '9', 'notification_channel_id' => 'H2H'],
        'logging.channels.doku_diagnostics' => ['driver' => 'single', 'path' => $this->observerLog, 'level' => 'warning', 'replace_placeholders' => false]]);
});

afterEach(function () {
    @unlink($this->observerKey);
    @unlink($this->observerLog);
    @unlink($this->observerMarker);
});

function observerBcaNotification(): array
{
    return ['partnerServiceId' => '   19008', 'customerNo' => '90000000001', 'virtualAccountNo' => '   1900890000000001', 'virtualAccountName' => 'Sentinel Visitor',
        'trxId' => 'UAT-BCA-R1-sentinel', 'paymentRequestId' => '7777777777', 'paidAmount' => ['value' => '10000.00', 'currency' => 'IDR'],
        'virtualAccountTrxType' => 'C', 'trxDateTime' => now()->toIso8601String(), 'additionalInfo' => ['note' => 'sentinel prose']];
}

/** @param  array<string, string>  $headers */
function observerPost(array $payload, array $headers = [])
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $time = now()->toIso8601String();
    $signature = base64_encode(hash_hmac('sha512', 'POST:/api/v1/webhooks/doku/va:observer-token:'.hash('sha256', $body).':'.$time, 'test-only-secret', true));
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PARTNER_ID' => 'MCH-TEST', 'HTTP_CHANNEL_ID' => 'H2H', 'HTTP_X_EXTERNAL_ID' => '202611100000001',
        'HTTP_AUTHORIZATION' => 'Bearer observer-token', 'HTTP_X_TIMESTAMP' => $time, 'HTTP_X_SIGNATURE' => $signature];

    return test()->call('POST', '/api/v1/webhooks/doku/va', [], [], [], array_replace($server, $headers), $body);
}

function enableObserver(int $secondsFromNow = 600): void
{
    file_put_contents((string) config('doku.webhook_observe_marker'), (string) (time() + $secondsFromNow));
}

it('records nothing while the observation marker is absent, expired or too far in the future', function (?int $offset) {
    if ($offset !== null) {
        enableObserver($offset);
    }

    observerPost(observerBcaNotification());

    expect(file_get_contents($this->observerLog))->toBe('');
})->with(['absent' => [null], 'expired' => [-5], 'more than one hour ahead' => [7200]]);

it('records a sanitized description before verification and leaves the answer unchanged', function () {
    $without = observerPost(observerBcaNotification());
    enableObserver();

    $with = observerPost(observerBcaNotification());

    expect($with->status())->toBe($without->status())->and($with->getContent())->toBe($without->getContent());
    $log = file_get_contents($this->observerLog);
    expect($log)->toContain('doku.webhook_observed')->and($log)->toContain('doku.webhook_answered');
    $entry = json_decode(substr(explode('
', $log)[0], strpos($log, '{')), true)['request'];
    expect($entry)->toMatchArray(['channel_id' => 'H2H', 'partner_id_matches' => true, 'declared_channel' => null, 'bank_by_channel_id' => 'bca', 'bank_by_partner_service_id' => 'bca',
        'bank_by_declared_channel' => null, 'body_is_json_object' => true])
        ->and($entry['signature'])->toMatchArray(['matches_minified_body' => true, 'matches_raw_body' => true, 'path_matches_configured' => true])
        ->and($entry['va_shape'])->toMatchArray(['digit_count' => 16, 'leading_spaces' => 3, 'equals_partner_plus_customer' => true])
        ->and($entry['body_shape']['paidAmount'])->toBe(['value' => '99999.99', 'currency' => 'IDR'])
        ->and($entry['body_shape']['virtualAccountTrxType'])->toBe('C')
        ->and($entry['header_names'])->toContain('channel-id', 'x-signature', 'authorization');
    foreach (['observer-token', 'test-only-secret', 'Sentinel Visitor', 'sentinel prose', '1900890000000001', '90000000001', 'UAT-BCA-R1-sentinel', '7777777777'] as $sentinel) {
        expect($log)->not->toContain($sentinel);
    }
});

it('reports a bad signature instead of hiding it and still refuses the notification', function () {
    enableObserver();

    $payload = observerBcaNotification();
    $payload['additionalInfo']['channel'] = 'VIRTUAL_ACCOUNT_BCA';

    $response = observerPost($payload, ['HTTP_X_SIGNATURE' => base64_encode('forged-signature-value')]);

    expect($response->status())->toBe(401);
    $log = file_get_contents($this->observerLog);
    $entry = json_decode(substr(explode('
', $log)[0], strpos($log, '{')), true)['request'];
    expect($entry['signature'])->toMatchArray(['matches_minified_body' => false, 'matches_raw_body' => false])
        ->and($log)->not->toContain('forged-signature-value')->and($log)->toContain('"http_status":401');
});

it('shows which gate would refuse a BCA notification that omits the channel without relaxing it', function () {
    enableObserver();

    $response = observerPost(observerBcaNotification());

    expect($response->status())->toBe(422);
    expect(file_get_contents($this->observerLog))->toContain('"http_status":422');
});
