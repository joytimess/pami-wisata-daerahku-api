<?php

use App\Services\BookingService;
use App\Services\Doku\DokuChannelRegistry;
use App\Services\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 08:00:00', 'UTC'));
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $this->registryKey = tempnam(sys_get_temp_dir(), 'doku-registry-');
    file_put_contents($this->registryKey, $pem);
    config(['operations.demo_payments_enabled' => false, 'app.debug' => false, 'doku.enabled' => true, 'doku.environment' => 'sandbox',
        'doku.client_id' => 'MCH-TEST', 'doku.secret_key' => 'test-only-secret', 'doku.merchant_private_key_path' => $this->registryKey,
        'doku.merchant_private_key_passphrase' => '', 'doku.partner_service_id' => '98829172', 'doku.customer_prefix' => '3',
        'doku.channels' => ['va_bni'], 'doku.verified_channels' => [], 'doku.webhook_url' => 'https://sandbox.example.com/api/v1/webhooks/doku/va']);
    Http::preventStrayRequests();
});

afterEach(function () {
    unlink($this->registryKey);
});

function registryBooking(): array
{
    [$destination, $date] = seedDestination(capacity: 5, price: 10000, days: 5);
    $owner = makeUser();
    $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date, 2) + ['idempotency_key' => (string) Str::uuid()]);
    $booking = app(BookingService::class)->decide(makeUser(2), $booking->guid, true);

    return [$owner, app(TicketService::class)->booking($booking->guid)];
}

it('makes only a fully configured BNI VA payable by default', function () {
    $reasons = app(DokuChannelRegistry::class)->reasons();

    expect($reasons['va_bni'])->toBeNull()
        ->and(collect($reasons)->except('va_bni')->every(fn (?string $reason) => $reason !== null))->toBeTrue();
});

it('disables only BNI when its merchant parameters are missing', function (string $key) {
    config([$key => '', 'doku.channels' => ['va_bni', 'va_bca', 'qris', 'ewallet_dana']]);
    $reasons = app(DokuChannelRegistry::class)->reasons();

    expect($reasons['va_bni'])->toBe('doku_not_configured')
        ->and(app(DokuChannelRegistry::class)->staticReason('va_bca'))->toBe('channel_not_implemented');
})->with(['doku.partner_service_id', 'doku.customer_prefix']);

it('never enables a channel only because it is allowlisted or marked verified', function () {
    config(['doku.channels' => array_keys(app(DokuChannelRegistry::class)->all()), 'doku.verified_channels' => array_keys(app(DokuChannelRegistry::class)->all())]);
    $reasons = app(DokuChannelRegistry::class)->reasons();

    foreach (['va_bca', 'va_bri', 'va_mandiri', 'qris', 'ewallet_dana', 'ewallet_shopeepay', 'ewallet_ovo'] as $code) {
        expect($reasons[$code])->not->toBeNull();
    }
    expect($reasons['va_bni'])->toBeNull();
});

it('fails every channel closed on default config, a typo environment and the kill switch', function (array $override) {
    config($override);

    expect(collect(app(DokuChannelRegistry::class)->reasons())->every(fn (?string $reason) => $reason !== null))->toBeTrue();
})->with([
    'default config' => [['doku.client_id' => '', 'doku.secret_key' => '', 'doku.channels' => [], 'doku.webhook_url' => '']],
    'wrong environment' => [['doku.environment' => 'staging']],
    'kill switch' => [['doku.enabled' => false]],
]);

it('keeps BNI grandfathered while any new channel needs explicit verification', function () {
    $registry = app(DokuChannelRegistry::class);

    expect($registry->staticReason('va_bni'))->toBeNull()
        ->and($registry->settlingPairs())->toBe([['virtual_account', 'bni']])
        ->and($registry->find('virtual_account', 'dana'))->toBeNull()
        ->and($registry->find('ewallet', 'dana')['code'])->toBe('ewallet_dana');
});

it('reports every family and channel on the methods endpoint without changing the existing family shape', function () {
    [$owner, $booking] = registryBooking();

    $response = $this->getJson('/api/v1/payments/methods?booking_guid='.$booking->guid, bearer($owner))->assertOk()
        ->assertJsonPath('data.can_pay', true)->assertJsonPath('data.integration_status', 'ready')
        ->assertJsonCount(3, 'data.methods')
        ->assertJsonPath('data.methods.0.code', 'qris')->assertJsonPath('data.methods.0.available', false)
        ->assertJsonPath('data.methods.1.code', 'virtual_account')->assertJsonPath('data.methods.1.available', true)
        ->assertJsonPath('data.methods.2.code', 'ewallet')->assertJsonPath('data.methods.2.available', false);

    $channels = collect($response->json('data.methods.1.channels'))->keyBy('channel');
    expect($channels->keys()->all())->toBe(['bni', 'bca', 'bri', 'mandiri'])
        ->and($channels['bni']['available'])->toBeTrue()
        ->and($channels['bca'])->toMatchArray(['available' => false, 'reason' => 'channel_not_implemented']);
    expect(collect($response->json('data.methods.2.channels'))->pluck('channel')->all())->toBe(['dana', 'shopeepay', 'ovo']);
});

it('rejects unimplemented channels before any attempt or provider request', function (string $method, string $channel) {
    [$owner, $booking] = registryBooking();
    config(['doku.channels' => ['va_bni', 'qris', 'ewallet_dana', 'va_bca'], 'doku.verified_channels' => ['qris', 'ewallet_dana', 'va_bca']]);

    $this->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts', ['method_code' => $method, 'channel' => $channel],
        bearer($owner) + ['Idempotency-Key' => 'unavailable-'.$channel])->assertStatus(409)->assertJsonPath('code', 'channel_not_implemented');
    $this->assertDatabaseCount('payment_attempts', 0);
    $this->assertDatabaseCount('payment_intents', 0);
    Http::assertNothingSent();
})->with([['qris', 'qris'], ['ewallet', 'dana'], ['virtual_account', 'bca']]);

it('rejects mismatched or unknown method and channel pairs as validation errors', function (string $method, string $channel) {
    [$owner, $booking] = registryBooking();

    $this->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts', ['method_code' => $method, 'channel' => $channel],
        bearer($owner) + ['Idempotency-Key' => 'mismatch'])->assertUnprocessable()->assertJsonValidationErrors('channel');
    $this->assertDatabaseCount('payment_attempts', 0);
})->with([['virtual_account', 'dana'], ['ewallet', 'bni'], ['qris', 'bni'], ['virtual_account', 'permata']]);
