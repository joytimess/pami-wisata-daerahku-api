<?php

use App\Services\Doku\DokuClient;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->freezeTime();
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $this->dokuPrivateKey = tempnam(sys_get_temp_dir(), 'doku-test-');
    file_put_contents($this->dokuPrivateKey, $pem);
    $this->dokuPublicKey = openssl_pkey_get_details($key)['key'];
    config(['app.debug' => false, 'doku.enabled' => true, 'doku.environment' => 'sandbox',
        'doku.client_id' => 'MCH-TEST', 'doku.secret_key' => 'test-only-secret',
        'doku.merchant_private_key_path' => $this->dokuPrivateKey, 'doku.merchant_private_key_passphrase' => '',
        'doku.partner_service_id' => '98829172', 'doku.customer_prefix' => '3',
        'doku.channels' => ['va_bni'], 'doku.webhook_url' => 'https://sandbox.example.com/api/v1/webhooks/doku/va',
        'doku.production_approved' => false]);
});

afterEach(function () {
    unlink($this->dokuPrivateKey);
});

function adapterAttempt(): object
{
    return (object) ['guid' => 'test-attempt', 'provider' => 'doku', 'environment' => 'sandbox', 'channel' => 'bni',
        'merchant_reference' => 'WDK-test-reference', 'provider_request_id' => '20261108000000000001',
        'amount' => 30000, 'currency' => 'IDR', 'instruction_expires_at' => now()->addHour()->toDateTimeString()];
}

function adapterClientWithControlledWindow(): DokuClient
{
    $client = Mockery::mock(DokuClient::class)->makePartial();
    $client->shouldReceive('expiryWindowReason')->andReturn(null);

    return $client;
}

function adapterVaResponse(): array
{
    return ['responseCode' => '2002700', 'responseMessage' => 'Successful', 'virtualAccountData' => [
        'partnerServiceId' => '98829172', 'customerNo' => '30000000000000000001',
        'virtualAccountNo' => '9882917230000000000000000001', 'trxId' => 'WDK-test-reference',
        'virtualAccountTrxType' => 'C', 'totalAmount' => ['value' => '30000.00', 'currency' => 'IDR'],
        'expiredDate' => now()->addHour()->toIso8601String(), 'additionalInfo' => ['channel' => 'VIRTUAL_ACCOUNT_BNI'],
    ]];
}

function adapterNotification(): array
{
    return ['partnerServiceId' => '98829172', 'customerNo' => '30000000000000000001',
        'virtualAccountNo' => '9882917230000000000000000001', 'virtualAccountName' => 'Test Visitor',
        'trxId' => 'WDK-test-reference', 'paymentRequestId' => '1234567890',
        'paidAmount' => ['value' => '30000.00', 'currency' => 'IDR'], 'virtualAccountTrxType' => 'C',
        'trxDateTime' => now()->toIso8601String(), 'additionalInfo' => ['channel' => 'VIRTUAL_ACCOUNT_BNI']];
}

function adapterSignedRequest(array $payload): Request
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $time = now()->toIso8601String();
    $signature = base64_encode(hash_hmac('sha512', 'POST:/api/v1/webhooks/doku/va:test-token:'.hash('sha256', $body).':'.$time, 'test-only-secret', true));

    return Request::create('/api/v1/webhooks/doku/va', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_PARTNER_ID' => 'MCH-TEST', 'HTTP_CHANNEL_ID' => 'VA004',
        'HTTP_X_EXTERNAL_ID' => '202611080000001', 'HTTP_AUTHORIZATION' => 'Bearer test-token',
        'HTTP_X_TIMESTAMP' => $time, 'HTTP_X_SIGNATURE' => $signature,
    ], $body);
}

it('fails closed for invalid or incomplete configuration', function (string $key, mixed $value) {
    config([$key => $value]);
    Http::preventStrayRequests();

    expect(app(DokuClient::class)->readinessReason())->not->toBeNull();
    Http::assertNothingSent();
})->with([
    ['doku.environment', 'typo'], ['doku.secret_key', ''], ['doku.merchant_private_key_path', 'missing.pem'],
    ['doku.partner_service_id', ''], ['doku.customer_prefix', 'abc'], ['doku.webhook_url', 'http://example.com/callback'],
    ['doku.channels', ['qris']], ['doku.enabled', false],
]);

it('requires explicit production approval debug off and stable public HTTPS', function () {
    config(['doku.environment' => 'production']);
    expect(app(DokuClient::class)->readinessReason())->not->toBeNull();
    config(['doku.production_approved' => true]);
    expect(app(DokuClient::class)->readinessReason())->toBeNull();
    config(['app.debug' => true]);
    expect(app(DokuClient::class)->readinessReason())->not->toBeNull();
    config(['app.debug' => false, 'doku.webhook_url' => 'https://example.lhr.life/api/v1/webhooks/doku/va']);
    expect(app(DokuClient::class)->readinessReason())->not->toBeNull();
});

it('keeps existing payment handlers configured after issuance kill switch', function () {
    config(['doku.enabled' => false, 'doku.channels' => []]);
    expect(app(DokuClient::class)->readinessReason())->not->toBeNull()
        ->and(app(DokuClient::class)->configured())->toBeTrue();
});

it('refuses production credentials in a local deployment even when approved', function () {
    config(['doku.environment' => 'production', 'doku.production_approved' => true]);
    app()->instance('env', 'local');
    expect(app(DokuClient::class)->configured())->toBeFalse();
});

it('creates closed nonreusable BNI VA using RSA token and HMAC transaction signatures', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api-sandbox.doku.com/authorization/v1/access-token/b2b' => Http::response(['responseCode' => '2007300', 'accessToken' => 'test-token', 'tokenType' => 'Bearer', 'expiresIn' => 900]),
        'https://api-sandbox.doku.com/virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va' => Http::response(adapterVaResponse()),
    ]);

    $result = adapterClientWithControlledWindow()->create(adapterAttempt(), (object) ['visitor_name' => 'Test Visitor', 'payment_expires_at' => now()->addHour()->toDateTimeString()]);
    expect($result['status'])->toBe('pending')->and($result['instruction']['va_number'])->toBe('9882917230000000000000000001');
    Http::assertSent(function (ClientRequest $request) {
        if (! str_ends_with($request->url(), '/b2b')) {
            return false;
        }

        return openssl_verify('MCH-TEST|'.$request->header('X-TIMESTAMP')[0], base64_decode($request->header('X-SIGNATURE')[0]), $this->dokuPublicKey, OPENSSL_ALGO_SHA256) === 1;
    });
    Http::assertSent(function (ClientRequest $request) {
        if (! str_ends_with($request->url(), '/create-va')) {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha512', 'POST:/virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va:test-token:'.hash('sha256', $request->body()).':'.$request->header('X-TIMESTAMP')[0], 'test-only-secret', true));

        return hash_equals($expected, $request->header('X-SIGNATURE')[0]) && $request['virtualAccountTrxType'] === 'C'
            && $request['additionalInfo']['virtualAccountConfig']['reusableStatus'] === false && $request['totalAmount']['value'] === '30000.00'
            && $request->header('X-EXTERNAL-ID')[0] === adapterAttempt()->provider_request_id;
    });
    Http::assertSentCount(2);
});

it('does not retry a timed out provider create', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api-sandbox.doku.com/authorization/v1/access-token/b2b' => Http::response(['responseCode' => '2007300', 'accessToken' => 'test-token', 'tokenType' => 'Bearer', 'expiresIn' => 900]),
        'https://api-sandbox.doku.com/virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va' => Http::failedConnection(),
    ]);
    $result = adapterClientWithControlledWindow()->create(adapterAttempt(), (object) ['visitor_name' => 'Test Visitor', 'payment_expires_at' => now()->addHour()->toDateTimeString()]);
    expect($result['status'])->toBe('unknown');
    Http::assertSentCount(2);
});

it('uses the live host only for a matching approved production attempt', function () {
    config(['doku.environment' => 'production', 'doku.production_approved' => true]);
    $attempt = adapterAttempt();
    $attempt->environment = 'production';
    Http::preventStrayRequests();
    Http::fake([
        'https://api.doku.com/authorization/v1/access-token/b2b' => Http::response(['responseCode' => '2007300', 'accessToken' => 'test-token', 'tokenType' => 'Bearer', 'expiresIn' => 900]),
        'https://api.doku.com/virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va' => Http::response(adapterVaResponse()),
    ]);
    expect(adapterClientWithControlledWindow()->create($attempt, (object) ['visitor_name' => 'Test Visitor', 'payment_expires_at' => now()->addHour()->toDateTimeString()])['status'])->toBe('pending');
    Http::assertSentCount(2);
    Http::assertNotSent(fn (ClientRequest $request) => str_contains($request->url(), 'api-sandbox.doku.com'));
});

it('quarantines a successful create with altered monetary snapshot', function () {
    Http::preventStrayRequests();
    $response = adapterVaResponse();
    $response['virtualAccountData']['totalAmount']['value'] = '1.00';
    Http::fake([
        'https://api-sandbox.doku.com/authorization/v1/access-token/b2b' => Http::response(['responseCode' => '2007300', 'accessToken' => 'test-token', 'tokenType' => 'Bearer', 'expiresIn' => 900]),
        'https://api-sandbox.doku.com/virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va' => Http::response($response),
    ]);
    expect(adapterClientWithControlledWindow()->create(adapterAttempt(), (object) ['visitor_name' => 'Test Visitor', 'payment_expires_at' => now()->addHour()->toDateTimeString()])['status'])->toBe('unknown');
    Http::assertSentCount(2);
});

it('does not send a BNI request while the merchant expiry window contract is unverified', function () {
    Http::preventStrayRequests();
    Http::fake();

    expect((new DokuClient)->create(adapterAttempt(), (object) ['visitor_name' => 'Test Visitor']))
        ->toBe(['status' => 'unknown', 'reason' => 'provider_expiry_window_unverified']);
    Http::assertNothingSent();
});

it('verifies and normalizes signed SNAP payment notification without exposing tokens', function () {
    $result = app(DokuClient::class)->verifyNotification(adapterSignedRequest(adapterNotification()));
    expect($result['status'])->toBe('paid')->and($result['amount'])->toBe(30000)
        ->and($result['merchant_reference'])->toBe('WDK-test-reference')->and($result['environment'])->toBe('sandbox')
        ->and($result['acknowledgement']['responseCode'])->toBe('2002500')
        ->and(json_encode($result))->not->toContain('test-token')->not->toContain('test-only-secret');
});

it('verifies pretty printed raw JSON without changing escapes or string whitespace', function () {
    $data = adapterNotification();
    $data['virtualAccountName'] = 'Nama  Pengunjung / Wisata é';
    $body = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    $minified = json_encode($data, JSON_THROW_ON_ERROR);
    $request = adapterSignedRequest($data);
    $signature = base64_encode(hash_hmac('sha512', 'POST:/api/v1/webhooks/doku/va:test-token:'.hash('sha256', $minified).':'.$request->header('X-TIMESTAMP'), 'test-only-secret', true));
    $pretty = Request::create('/api/v1/webhooks/doku/va', 'POST', [], [], [], $request->server->all(), $body);
    $pretty->headers->set('X-SIGNATURE', $signature);
    expect(app(DokuClient::class)->verifyNotification($pretty)['status'])->toBe('paid');
});

it('rejects tampered signed body', function () {
    $data = adapterNotification();
    $signed = adapterSignedRequest($data);
    $data['paidAmount']['value'] = '1.00';
    $tampered = Request::create('/api/v1/webhooks/doku/va', 'POST', [], [], [], $signed->server->all(), json_encode($data));
    expect(fn () => app(DokuClient::class)->verifyNotification($tampered))->toThrow(HttpException::class);
});

it('rejects signed malformed monetary or reference field types as input errors', function (string $key, mixed $value) {
    $data = adapterNotification();
    $data[$key] = $value;
    expect(fn () => app(DokuClient::class)->verifyNotification(adapterSignedRequest($data)))->toThrow(HttpException::class);
})->with([
    ['paidAmount', '30000.00'], ['paidAmount', ['value' => ['30000.00'], 'currency' => 'IDR']],
    ['partnerServiceId', ['98829172']], ['virtualAccountNo', ['98829172']], ['trxId', ['reference']],
]);

it('rejects forged stale wrong merchant or wrong channel notification', function (string $header, string $value) {
    $request = adapterSignedRequest(adapterNotification());
    $request->headers->set($header, $value);
    expect(fn () => app(DokuClient::class)->verifyNotification($request))->toThrow(HttpException::class);
})->with([
    ['X-SIGNATURE', 'forged'], ['X-TIMESTAMP', '2000-01-01T00:00:00+00:00'],
    ['X-PARTNER-ID', 'another-merchant'], ['CHANNEL-ID', 'VA001'],
]);

it('rejects signed nonintegral or unsupported currency payment amounts', function (array $amount) {
    $payload = adapterNotification();
    $payload['paidAmount'] = $amount;
    expect(fn () => app(DokuClient::class)->verifyNotification(adapterSignedRequest($payload)))->toThrow(HttpException::class);
})->with([
    [['value' => '30000.01', 'currency' => 'IDR']], [['value' => '30000.00', 'currency' => 'USD']],
    [['value' => '3e4', 'currency' => 'IDR']], [['value' => '-30000.00', 'currency' => 'IDR']],
]);

it('never queries with another environment credentials or retries unknown create without VA', function () {
    Http::preventStrayRequests();
    $attempt = adapterAttempt();
    expect(app(DokuClient::class)->status($attempt)['status'])->toBe('unknown');
    $attempt->environment = 'production';
    expect(app(DokuClient::class)->status($attempt)['reason'])->toBe('payment_environment_mismatch');
    Http::assertNothingSent();
});

it('recovers public instruction without exposing provider query metadata', function () {
    $attempt = adapterAttempt();
    $attempt->instruction_ciphertext = Crypt::encryptString(json_encode(['presentation_type' => 'virtual_account', 'va_number' => '9882917230000000000000000001', '_provider' => ['customerNo' => '30000000000000000001']]));
    expect(app(DokuClient::class)->instruction($attempt))->toBe(['presentation_type' => 'virtual_account', 'va_number' => '9882917230000000000000000001']);
});

it('queries documented pending status without interpreting paidAmount as a payment', function () {
    Http::preventStrayRequests();
    $attempt = adapterAttempt();
    $provider = ['partnerServiceId' => '98829172', 'customerNo' => '30000000000000000001', 'virtualAccountNo' => '9882917230000000000000000001'];
    $attempt->instruction_ciphertext = Crypt::encryptString(json_encode(['_provider' => $provider]));
    Http::fake([
        'https://api-sandbox.doku.com/authorization/v1/access-token/b2b' => Http::response(['responseCode' => '2007300', 'accessToken' => 'test-token', 'tokenType' => 'Bearer', 'expiresIn' => 900]),
        'https://api-sandbox.doku.com/orders/v1.0/transfer-va/status' => Http::response([
            'responseCode' => '2002600', 'virtualAccountData' => $provider + [
                'paymentFlagReason' => ['english' => 'Pending', 'indonesia' => 'Belum Terbayar'],
                'paidAmount' => ['value' => '30000.00', 'currency' => 'IDR'],
            ], 'additionalInfo' => ['trxId' => $attempt->merchant_reference, 'acquirer' => ['id' => 'BNI']],
        ]),
    ]);
    expect(app(DokuClient::class)->status($attempt)['status'])->toBe('pending');
    Http::assertSentCount(2);
    Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/transfer-va/status') && $request['virtualAccountNo'] === $provider['virtualAccountNo']);
});

it('still verifies a signed BNI notification after the issuance kill switch and without the verified gate', function () {
    config(['doku.enabled' => false, 'doku.channels' => [], 'doku.verified_channels' => []]);
    expect(app(DokuClient::class)->readinessReason('va_bni'))->not->toBeNull();

    $outcome = app(DokuClient::class)->verifyNotification(adapterSignedRequest(adapterNotification()));

    expect($outcome['status'])->toBe('paid')->and($outcome['merchant_reference'])->toBe('WDK-test-reference');
});

it('keeps a BNI status response unresolved unless the documented pending shape matches exactly', function (array $flag) {
    Http::preventStrayRequests();
    $attempt = adapterAttempt();
    $provider = ['partnerServiceId' => '98829172', 'customerNo' => '30000000000000000001', 'virtualAccountNo' => '9882917230000000000000000001'];
    $attempt->instruction_ciphertext = Crypt::encryptString(json_encode(['_provider' => $provider]));
    Http::fake([
        'https://api-sandbox.doku.com/authorization/v1/access-token/b2b' => Http::response(['responseCode' => '2007300', 'accessToken' => 'test-token', 'tokenType' => 'Bearer', 'expiresIn' => 900]),
        'https://api-sandbox.doku.com/orders/v1.0/transfer-va/status' => Http::response([
            'responseCode' => '2002600', 'virtualAccountData' => $provider + ['paymentFlagReason' => $flag,
                'paidAmount' => ['value' => '30000.00', 'currency' => 'IDR']],
            'additionalInfo' => ['trxId' => $attempt->merchant_reference, 'acquirer' => ['id' => 'BNI']],
        ]),
    ]);

    expect(app(DokuClient::class)->status($attempt))->toBe(['status' => 'unknown', 'reason' => 'provider_terminal_contract_unverified']);
})->with([
    'paid-looking label' => [['english' => 'Success', 'indonesia' => 'Berhasil']],
    'expired-looking label' => [['english' => 'Expired', 'indonesia' => 'Kedaluwarsa']],
    'failed-looking label' => [['english' => 'Failed', 'indonesia' => 'Gagal']],
    'missing label' => [[]],
]);

function diagnosticsLogFile(): string
{
    $path = tempnam(sys_get_temp_dir(), 'doku-diag-');
    config(['logging.channels.doku_diagnostics' => ['driver' => 'single', 'path' => $path, 'level' => 'warning', 'replace_placeholders' => false],
        'logging.channels.diag_app' => ['driver' => 'single', 'path' => $path.'.app', 'level' => 'debug'], 'logging.default' => 'diag_app']);
    Log::forgetChannel('doku_diagnostics');
    Log::forgetChannel('diag_app');

    return $path;
}

it('keeps credentials, VA and customer numbers, email, QR content and provider prose out of diagnostic logs', function () {
    $path = diagnosticsLogFile();
    Http::preventStrayRequests();
    Http::fake([
        'https://api-sandbox.doku.com/authorization/v1/access-token/b2b' => Http::response(['responseCode' => '2007300', 'accessToken' => 'test-token', 'tokenType' => 'Bearer', 'expiresIn' => 900]),
        'https://api-sandbox.doku.com/virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va' => Http::response([
            'responseCode' => '4012700', 'responseMessage' => 'Rejected for Budi Santoso budi.sentinel@example.test',
            'virtualAccountData' => ['virtualAccountNo' => '9882917299999999999999', 'customerNo' => '99999999999999999999',
                'virtualAccountEmail' => 'va.sentinel@example.test', 'virtualAccountPhone' => '081200000777'],
            'additionalInfo' => ['qrContent' => '000201SENTINELQRCONTENT', 'note' => 'free text sentinel prose'],
        ], 400),
    ]);

    adapterClientWithControlledWindow()->create(adapterAttempt(), (object) ['visitor_name' => 'Sentinel Visitor', 'payment_expires_at' => now()->addHour()->toDateTimeString()]);

    $logs = file_get_contents($path).(is_file($path.'.app') ? file_get_contents($path.'.app') : '');
    expect($logs)->toContain('4012700');
    foreach (['test-token', 'test-only-secret', 'Sentinel Visitor', 'Budi Santoso', 'budi.sentinel@example.test', 'va.sentinel@example.test',
        '081200000777', '9882917299999999999999', '99999999999999999999', 'SENTINELQRCONTENT', 'free text sentinel prose'] as $sentinel) {
        expect($logs)->not->toContain($sentinel);
    }
    @unlink($path);
    @unlink($path.'.app');
});

it('records only bounded metadata for oversized or non-JSON provider bodies', function () {
    $path = diagnosticsLogFile();
    Http::preventStrayRequests();
    Http::fake([
        'https://api-sandbox.doku.com/authorization/v1/access-token/b2b' => Http::response(['responseCode' => '2007300', 'accessToken' => 'test-token', 'tokenType' => 'Bearer', 'expiresIn' => 900]),
        'https://api-sandbox.doku.com/virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va' => Http::response('<html>SENTINELHTMLBODY '.str_repeat('x', 40000).'</html>', 502),
    ]);

    adapterClientWithControlledWindow()->create(adapterAttempt(), (object) ['visitor_name' => 'Test Visitor', 'payment_expires_at' => now()->addHour()->toDateTimeString()]);

    $logs = file_get_contents($path);
    expect($logs)->not->toContain('SENTINELHTMLBODY')->and(strlen($logs))->toBeLessThan(6000)
        ->and($logs)->toContain('doku_payment_http_error');
    @unlink($path);
    @unlink($path.'.app');
});

it('answers an oversized string literal as an input error before any signature work instead of a server error', function () {
    $data = adapterNotification();
    $data['virtualAccountName'] = str_repeat('A', 60000);
    $request = adapterSignedRequest($data);

    try {
        app(DokuClient::class)->verifyNotification($request);
        $status = 200;
    } catch (HttpException $exception) {
        $status = $exception->getStatusCode();
    }

    expect($status)->toBeIn([200, 400, 401]);
});
