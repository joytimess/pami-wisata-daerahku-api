<?php

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const PROBE_PATH = '/direct-debit/core/v1/debit/payment-host-to-host';

beforeEach(function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $this->probeKey = tempnam(sys_get_temp_dir(), 'doku-probe-');
    file_put_contents($this->probeKey, $pem);
    config(['app.debug' => false, 'doku.enabled' => true, 'doku.environment' => 'sandbox', 'doku.client_id' => 'MCH-TEST',
        'doku.secret_key' => 'test-only-secret', 'doku.merchant_private_key_path' => $this->probeKey, 'doku.merchant_private_key_passphrase' => '',
        'doku.channels' => ['ewallet_dana'], 'doku.verified_channels' => ['ewallet_dana'],
        'doku.ewallet' => ['return_url' => 'https://tunnel.example.org/payment/return', 'redirect_hosts' => ['pay.wallet-fixture.example.com'], 'shopeepay_payment_path' => ''],
        'doku.webhook_url' => 'https://sandbox.example.com/api/v1/webhooks/doku/va']);
    Http::preventStrayRequests();
});

afterEach(function () {
    unlink($this->probeKey);
});

function probeFake(Closure|array $create): void
{
    Http::fake([
        'https://api-sandbox.doku.com/authorization/v1/access-token/b2b' => Http::response(['responseCode' => '2007300', 'accessToken' => 'test-token', 'tokenType' => 'Bearer', 'expiresIn' => 900]),
        'https://api-sandbox.doku.com'.PROBE_PATH => $create instanceof Closure ? $create : Http::response($create),
    ]);
}

/** The payment-create requests in the order they were sent. */
function probeSent(): Collection
{
    return Http::recorded(fn (ClientRequest $request) => str_contains($request->url(), 'payment-host-to-host'))->values()->map(fn ($pair) => $pair[0]);
}

it('prints the bodies, the masked headers and the A/B diff in a dry run without sending anything', function () {
    $this->artisan('doku:probe-dana-create --dry-run --device-id=Dev123abc --ip-address=198.51.100.7')
        ->expectsOutputToContain('only in B : validUpTo')
        ->expectsOutputToContain('only in B : pointOfInitiation')
        ->expectsOutputToContain('differs   : urlParam.url  A=https://example.com/payment/return  B=https://tunnel.example.org/payment/return')
        ->expectsOutputToContain('Probe D (A + validUpTo)')
        ->expectsOutputToContain('Probe E (A + pointOfInitiation)')
        ->expectsOutputToContain('Probe F (A with X-TIMESTAMP in Asia/Jakarta)')
        ->expectsOutputToContain('Probe G (F + X-DEVICE-ID + X-IP-ADDRESS)')
        ->expectsOutputToContain('X-PARTNER-ID: MCH-TEST')
        ->expectsOutputToContain('X-SIGNATURE: [REDACTED]')
        ->expectsOutputToContain('Authorization: [REDACTED]')
        ->expectsOutputToContain('X-DEVICE-ID: Dev123abc')
        ->expectsOutputToContain('X-IP-ADDRESS: 198.51.100.7')
        ->doesntExpectOutputToContain('test-only-secret')
        ->expectsOutputToContain('Dry run: nothing was sent.')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('sends exactly seven signed probes with unique invoices and external ids and writes nothing to the database', function () {
    probeFake(fn () => Http::response(['responseCode' => '5005400', 'responseMessage' => 'General Error'], 500));
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $this->artisan('doku:probe-dana-create --device-id=Dev123abc --ip-address=198.51.100.7')->assertSuccessful();

    $sent = probeSent();
    expect($sent)->toHaveCount(7);
    $bodies = $sent->map(fn (ClientRequest $request) => $request->data());
    expect($bodies->pluck('partnerReferenceNo')->unique())->toHaveCount(7)
        ->and($sent->map(fn (ClientRequest $request) => $request->header('X-EXTERNAL-ID')[0])->unique())->toHaveCount(7)
        ->and($bodies[0]['urlParam']['url'])->toBe('https://example.com/payment/return')
        ->and($bodies[2]['urlParam']['url'])->toBe('https://tunnel.example.org/payment/return')
        ->and(array_keys($bodies[0]))->toBe(['partnerReferenceNo', 'amount', 'urlParam', 'additionalInfo'])
        ->and($bodies[0]['amount'])->toBe(['value' => '100000.00', 'currency' => 'IDR'])
        ->and($bodies[0]['additionalInfo'])->toBe(['channel' => 'EMONEY_DANA_SNAP'])
        ->and(array_keys($bodies[3]))->toBe(['partnerReferenceNo', 'amount', 'urlParam', 'additionalInfo', 'validUpTo'])
        ->and($bodies[3]['validUpTo'])->toBe($bodies[1]['validUpTo'])
        ->and(array_keys($bodies[4]))->toBe(['partnerReferenceNo', 'amount', 'urlParam', 'additionalInfo', 'pointOfInitiation'])
        ->and($bodies[4]['pointOfInitiation'])->toBe('app')
        ->and(array_keys($bodies[5]))->toBe(array_keys($bodies[0]))
        ->and(array_keys($bodies[6]))->toBe(array_keys($bodies[0]))
        ->and($bodies[5]['urlParam']['url'])->toBe('https://example.com/payment/return');
    expect(array_filter($queries, fn (string $sql) => preg_match('/^\s*(insert|update|delete)/i', $sql)))->toBe([]);
});

it('signs probes F and G from the exact Jakarta timestamp they send and adds the device headers only to G', function () {
    probeFake(fn () => Http::response(['responseCode' => '5005400', 'responseMessage' => 'General Error'], 500));

    $this->artisan('doku:probe-dana-create --device-id=Dev123abc --ip-address=198.51.100.7')->assertSuccessful();

    $sent = probeSent();
    foreach ([0, 1, 2, 3, 4] as $index) {
        expect($sent[$index]->header('X-TIMESTAMP')[0])->toEndWith('+00:00')
            ->and($sent[$index]->hasHeader('X-DEVICE-ID'))->toBeFalse()->and($sent[$index]->hasHeader('X-IP-ADDRESS'))->toBeFalse();
    }
    foreach ([5, 6] as $index) {
        $timestamp = $sent[$index]->header('X-TIMESTAMP')[0];
        $expected = base64_encode(hash_hmac('sha512', 'POST:'.PROBE_PATH.':test-token:'.hash('sha256', $sent[$index]->body()).':'.$timestamp, 'test-only-secret', true));
        expect($timestamp)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+07:00$/')
            ->and(hash_equals($expected, $sent[$index]->header('X-SIGNATURE')[0]))->toBeTrue();
    }
    expect($sent[5]->hasHeader('X-DEVICE-ID'))->toBeFalse()->and($sent[5]->hasHeader('X-IP-ADDRESS'))->toBeFalse()
        ->and($sent[6]->header('X-DEVICE-ID'))->toBe(['Dev123abc'])->and($sent[6]->header('X-IP-ADDRESS'))->toBe(['198.51.100.7']);
});

it('reports HTTP status, DOKU responseCode and PASS or FAIL for every probe', function () {
    $calls = 0;
    probeFake(function () use (&$calls) {
        $calls++;

        return match ($calls) {
            1 => Http::response(['responseCode' => '2005400', 'responseMessage' => 'Successful', 'webRedirectUrl' => 'https://pay.wallet-fixture.example.com/x?t=1']),
            2 => Http::response(['responseCode' => '5005400', 'responseMessage' => 'General Error'], 500),
            3 => Http::response(['responseCode' => '4295400', 'responseMessage' => 'Too Many'], 429),
            default => Http::response(['responseCode' => '5005401', 'responseMessage' => 'Retry'], 500),
        };
    });

    $this->artisan('doku:probe-dana-create')
        ->expectsTable(['Probe', 'HTTP', 'responseCode', 'Result', 'Note'], [
            ['A', '200', '2005400', 'PASS', 'redirect host pay.wallet-fixture.example.com'],
            ['B', '500', '5005400', 'FAIL', 'rejected by DOKU'],
            ['C', '429', '4295400', 'FAIL', 'rejected by DOKU'],
            ['D', '500', '5005401', 'FAIL', 'rejected by DOKU'],
            ['E', '500', '5005401', 'FAIL', 'rejected by DOKU'],
            ['F', '500', '5005401', 'FAIL', 'rejected by DOKU'],
            ['G', '500', '5005401', 'FAIL', 'rejected by DOKU'],
        ])
        ->assertSuccessful();
});

it('rejects a device id with symbols and an invalid IPv4 address before sending anything', function (string $option) {
    $this->artisan('doku:probe-dana-create '.$option)->assertFailed();

    Http::assertNothingSent();
})->with([
    'symbols in device id' => ['--device-id=dev-123_x'],
    'ipv6 address' => ['--ip-address=2001:db8::1'],
    'not an ip' => ['--ip-address=999.1.1.1'],
]);

it('refuses to run outside the sandbox', function () {
    config(['doku.environment' => 'production']);

    $this->artisan('doku:probe-dana-create')->expectsOutputToContain('not sandbox')->assertFailed();

    Http::assertNothingSent();
});

it('does not retry and does not print credentials when the probes fail', function () {
    probeFake(fn () => Http::response(['responseCode' => '5005401', 'responseMessage' => 'echo test-token test-only-secret'], 500));

    $this->artisan('doku:probe-dana-create')->doesntExpectOutputToContain('test-token')->doesntExpectOutputToContain('test-only-secret')->assertSuccessful();

    expect(probeSent())->toHaveCount(7);
});
