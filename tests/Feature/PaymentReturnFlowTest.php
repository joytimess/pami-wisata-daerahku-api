<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const RETURN_FINGERPRINT = '14:6D:E9:83:C5:73:06:50:D8:EE:B9:95:2F:34:FC:64:16:A0:83:42:E6:1D:BE:A8:8A:04:96:B2:3F:CF:44:E5';

beforeEach(function () {
    Http::preventStrayRequests();
    config(['doku.ewallet.return_url' => 'https://api.wisata-fixture.example.com/payment/return', 'mobile.android.package' => 'id.wisatadaerahku.app']);
});

it('serves the documented return URL path as a real route', function () {
    expect(route('payment.return', [], false))->toBe('/payment/return')
        ->and(parse_url((string) config('doku.ewallet.return_url'), PHP_URL_PATH))->toBe(route('payment.return', [], false));
    $this->get('/payment/return')->assertOk();
});

it('renders a private neutral landing page that never claims or records a payment', function () {
    DB::enableQueryLog();
    $response = $this->get('/payment/return');

    $response->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertSee('dikonfirmasi oleh server')->assertDontSee('berhasil')->assertDontSee('lunas');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->and($response->headers->has('Set-Cookie'))->toBeFalse();
    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => preg_match('/^\s*(insert|update|delete)/i', $sql))->all())->toBe([]);
    Http::assertNothingSent();
});

it('opens the app with an explicit package intent built from configuration only', function () {
    $this->get('/payment/return')->assertOk()
        ->assertSee('intent://api.wisata-fixture.example.com/payment/return#Intent;scheme=https;package=id.wisatadaerahku.app;end', false);
});

it('derives the intent host from the application URL when no return URL is configured', function () {
    config(['doku.ewallet.return_url' => '', 'app.url' => 'https://fallback.example.com']);

    $this->get('/payment/return', ['Host' => 'evil.example.net'])->assertSee('intent://fallback.example.com/payment/return#', false)->assertDontSee('evil.example.net');
});

it('treats the result query only as a whitelisted display hint', function (string $query, string $text, string $hint) {
    $response = $this->get('/payment/return'.$query)->assertOk()->assertSee($text)->assertDontSee('berhasil');
    expect($response->getContent())->toContain($hint === '' ? '#Intent;scheme=https' : 'payment/return?result='.$hint.'#Intent;scheme=https');
})->with([
    'success hint' => ['?result=success', 'Proses di e-wallet telah ditutup', 'success'],
    'cancel hint' => ['?result=cancel', 'dibatalkan atau belum diselesaikan', 'cancel'],
    'error hint' => ['?result=error', 'kendala pada e-wallet', 'error'],
    'unknown hint' => ['?result=PAID', 'sedang diperiksa oleh server', ''],
    'array hint' => ['?result[]=success', 'sedang diperiksa oleh server', ''],
]);

it('never reflects other query input into the page', function () {
    $content = $this->get('/payment/return?result=success&status=PAID&x="><script>alert(1)</script>&redirect=https://evil.example.net')->assertOk()->getContent();

    expect($content)->not->toContain('<script')->not->toContain('evil.example.net')->not->toContain('alert(1)')->not->toContain('status=PAID');
});

it('publishes Android app link association only when fingerprints are configured', function () {
    config(['mobile.android.sha256_fingerprints' => []]);
    $this->getJson('/.well-known/assetlinks.json')->assertNotFound();

    config(['mobile.android.sha256_fingerprints' => [RETURN_FINGERPRINT, 'not-a-fingerprint', strtolower(RETURN_FINGERPRINT)]]);
    $response = $this->get('/.well-known/assetlinks.json')->assertOk()->assertHeader('Content-Type', 'application/json');

    expect($response->json())->toBe([[
        'relation' => ['delegate_permission/common.handle_all_urls'],
        'target' => ['namespace' => 'android_app', 'package_name' => 'id.wisatadaerahku.app', 'sha256_cert_fingerprints' => [RETURN_FINGERPRINT]],
    ]]);
});
