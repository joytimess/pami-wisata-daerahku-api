<?php

require_once __DIR__.'/Support/MidtransFixtures.php';

use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    mtxConfig();
    Http::preventStrayRequests();
});

function nativePng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN1cAAAAASUVORK5CYII=');
}

it('includes authoritative server time in snapshot and methods', function () {
    [$owner, $booking] = mtxPayable();
    $this->freezeTime();
    foreach (['/api/v1/bookings/'.$booking->guid.'/payment-intent', '/api/v1/payments/methods?booking_guid='.$booking->guid] as $path) {
        $this->getJson($path, bearer($owner))->assertOk()->assertJsonPath('data.server_time', now()->toIso8601String());
    }
});

it('proxies authentic private PNG without provider URL or credentials', function () {
    [$owner, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, method: 'qris', channel: 'qris');
    Http::fake(['*' => Http::response(nativePng(), 200, ['Content-Type' => 'image/png'])]);
    $response = $this->get('/api/v1/bookings/'.$booking->guid.'/payment-attempts/'.$attempt->guid.'/qr', bearer($owner))
        ->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->getContent())->toBe(nativePng())->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    Http::assertSent(fn ($request) => $request->url() === 'https://api.sandbox.midtrans.com/v2/qris/'.$attempt->provider_reference.'/qr-code'
        && ! $request->hasHeader('Authorization') && $request->method() === 'GET');
    expect(json_encode(app(PaymentService::class)->show($owner, $booking->guid)))->not->toContain('image_url', 'SB-Mid-server');
});

it('rejects unauthorized mismatched expired or inactive attempts before HTTP', function (string $case) {
    [$owner, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, method: 'qris', channel: 'qris');
    $headers = bearer($owner);
    $guid = $booking->guid;
    match ($case) {
        'guest' => $headers = [],
        'foreign' => $headers = bearer(makeUser()),
        'admin' => $headers = bearer(makeUser(2)),
        'binding' => $guid = mtxPayable()[1]->guid,
        'expired' => DB::table('bookings')->where('guid', $guid)->update(['payment_expires_at' => now()]),
        'attempt_expired' => DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['instruction_expires_at' => now()]),
        'unknown' => DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['status' => 'unknown']),
        'environment' => DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['environment' => 'production']),
        'intent' => DB::table('payment_intents')->where('guid', $attempt->payment_intent_guid)->update(['status' => 'needs_review']),
        'amount' => DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['amount' => 1]),
    };
    $this->getJson('/api/v1/bookings/'.$guid.'/payment-attempts/'.$attempt->guid.'/qr', $headers)->assertStatus($case === 'guest' ? 401 : 404);
    Http::assertNothingSent();
})->with(['guest', 'foreign', 'admin', 'binding', 'expired', 'attempt_expired', 'unknown', 'environment', 'intent', 'amount']);

it('rejects untrusted image URLs before HTTP', function (string $url) {
    [$owner, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, method: 'qris', channel: 'qris');
    DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['instruction_ciphertext' => Crypt::encryptString(json_encode([
        'presentation_type' => 'qr_image', '_provider' => ['image_url' => $url],
    ]))]);
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts/'.$attempt->guid.'/qr', bearer($owner))->assertNotFound();
    Http::assertNothingSent();
})->with(['http://api.sandbox.midtrans.com/v2/qris/x/qr-code', 'https://evil.test/qr.png', 'https://api.midtrans.com/v2/qris/x/qr-code',
    'https://api.sandbox.midtrans.com/v2/qris/foreign/qr-code', 'https://user:pass@api.sandbox.midtrans.com/v2/qris/x/qr-code',
    'https://api.sandbox.midtrans.com:443/v2/qris/x/qr-code', 'https://api.sandbox.midtrans.com/v2/qris/x/qr-code?x=1']);

it('rejects redirect MIME size invalid PNG and connection failures', function (string $case) {
    [$owner, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, method: 'qris', channel: 'qris');
    Http::fake(['*' => match ($case) {
        'redirect' => Http::response('', 302, ['Location' => 'https://evil.test/a']),
        'mime' => Http::response(nativePng(), 200, ['Content-Type' => 'text/html']),
        'large' => Http::response(nativePng().str_repeat('x', 1048576), 200, ['Content-Type' => 'image/png']),
        'invalid' => Http::response('not a PNG', 200, ['Content-Type' => 'image/png']),
        'timeout' => Http::failedConnection(),
    }]);
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts/'.$attempt->guid.'/qr', bearer($owner))->assertStatus(502);
    Http::assertSentCount(1);
})->with(['redirect', 'mime', 'large', 'invalid', 'timeout']);

it('discards the image when cutoff passes during download', function () {
    [$owner, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, method: 'qris', channel: 'qris');
    Http::fake(function () use ($booking) {
        $this->travelTo(CarbonImmutable::parse($booking->payment_expires_at, 'UTC'));

        return Http::response(nativePng(), 200, ['Content-Type' => 'image/png']);
    });
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts/'.$attempt->guid.'/qr', bearer($owner))->assertNotFound();
});
