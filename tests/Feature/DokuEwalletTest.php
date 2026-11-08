<?php

use App\Exceptions\BookingException;
use App\Services\BookingService;
use App\Services\Doku\DokuChannelRegistry;
use App\Services\DokuPaymentService;
use App\Services\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Hosts and URLs below are fixtures for this test only. The real redirect hosts must be confirmed by the operator.
 */
const WALLET_PAYMENT_PATH = '/direct-debit/core/v2/debit/payment-host-to-host';
const WALLET_REDIRECT = 'https://pay.wallet-fixture.example.com/checkout?token=abc';

beforeEach(function () {
    walletSetStatus(null);
    $this->travelTo(CarbonImmutable::parse('2026-11-08 08:00:00', 'UTC'));
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $this->walletKey = tempnam(sys_get_temp_dir(), 'doku-wallet-');
    file_put_contents($this->walletKey, $pem);
    config(['operations.demo_payments_enabled' => false, 'app.debug' => false, 'doku.enabled' => true, 'doku.environment' => 'sandbox',
        'doku.client_id' => 'MCH-TEST', 'doku.secret_key' => 'test-only-secret', 'doku.merchant_private_key_path' => $this->walletKey,
        'doku.merchant_private_key_passphrase' => '', 'doku.partner_service_id' => '', 'doku.customer_prefix' => '',
        'doku.channels' => ['ewallet_dana', 'ewallet_shopeepay'], 'doku.verified_channels' => ['ewallet_dana', 'ewallet_shopeepay'],
        'doku.ewallet' => ['return_url' => 'https://app.example.com/payment/return', 'redirect_hosts' => ['pay.wallet-fixture.example.com'],
            'shopeepay_payment_path' => WALLET_PAYMENT_PATH],
        'doku.webhook_url' => 'https://sandbox.example.com/api/v1/webhooks/doku/va']);
    Http::preventStrayRequests();
});

afterEach(function () {
    unlink($this->walletKey);
});

function walletBooking(): array
{
    [$destination, $date] = seedDestination(capacity: 5, price: 10000, days: 5);
    $owner = makeUser();
    $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date, 2) + ['idempotency_key' => (string) Str::uuid()]);
    $booking = app(BookingService::class)->decide(makeUser(2), $booking->guid, true);

    return [$owner, app(TicketService::class)->booking($booking->guid)];
}

function walletFake(string $path, array|Closure|null $create = null, array|Closure|null $status = null, mixed $token = null): void
{
    Http::fake([
        'https://api-sandbox.doku.com/authorization/v1/access-token/b2b' => $token ?? Http::response(['responseCode' => '2007300', 'accessToken' => 'test-token', 'tokenType' => 'Bearer', 'expiresIn' => 900]),
        'https://api-sandbox.doku.com'.$path => $create ?? fn (ClientRequest $request) => Http::response([
            'responseCode' => '2005400', 'responseMessage' => 'Successful', 'webRedirectUrl' => WALLET_REDIRECT, 'partnerReferenceNo' => $request['partnerReferenceNo']]),
        'https://api-sandbox.doku.com/orders/v1.0/debit/status' => fn (ClientRequest $request) => ($GLOBALS['walletStatus'] ?? $status ?? fn (ClientRequest $request) => Http::response([
            'responseCode' => '2005500', 'responseMessage' => 'Successful', 'originalPartnerReferenceNo' => $request['originalPartnerReferenceNo'],
            'originalReferenceNo' => 'WREF123', 'serviceCode' => '55', 'latestTransactionStatus' => '03', 'transactionStatusDesc' => 'Pending']))($request),
    ]);
}

/** Http::fake keeps the first matching stub, so later status responses are swapped through this hook instead. */
function walletSetStatus(?Closure $status): void
{
    $GLOBALS['walletStatus'] = $status;
}

function walletPaidStatus(array $override = []): Closure
{
    return fn (ClientRequest $request) => Http::response(array_replace([
        'responseCode' => '2005500', 'responseMessage' => 'Successful', 'originalPartnerReferenceNo' => $request['originalPartnerReferenceNo'],
        'originalReferenceNo' => 'WREF123', 'serviceCode' => '55', 'latestTransactionStatus' => '00', 'transactionStatusDesc' => 'Success',
        'paidTime' => now()->toIso8601String(), 'transAmount' => ['value' => '20000.00', 'currency' => 'IDR']], $override));
}

function walletStart(object $owner, object $booking, string $wallet, string $key = 'wallet-start')
{
    return test()->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts', ['method_code' => 'ewallet', 'channel' => $wallet],
        bearer($owner) + ['Idempotency-Key' => $key]);
}

function walletReconcile(string $attemptGuid): void
{
    DB::table('payment_attempts')->where('guid', $attemptGuid)->update(['next_reconcile_at' => now()->subMinute()]);
    app(DokuPaymentService::class)->reconcile($attemptGuid);
}

it('creates a one-time redirect payment with the documented request shape', function (string $wallet, string $path, string $channel) {
    config(['doku.ewallet.shopeepay_payment_path' => WALLET_PAYMENT_PATH]);
    [$owner, $booking] = walletBooking();
    walletFake($path);

    $data = walletStart($owner, $booking, $wallet)->assertOk()->json('data');

    expect($data)->toMatchArray(['status' => 'pending', 'method_code' => 'ewallet', 'channel' => $wallet, 'payment_status' => 'unverified', 'amount' => 20000])
        ->and($data['instruction'])->toBe(['presentation_type' => 'ewallet_redirect', 'redirect_url' => WALLET_REDIRECT, 'requires_external_authorization' => true]);
    expect(DB::table('payment_attempts')->value('instruction_ciphertext'))->not->toContain('pay.wallet-fixture');
    expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->not->toBe('confirmed');
    Http::assertSent(function (ClientRequest $request) use ($path, $channel, $wallet) {
        if ($request->url() !== 'https://api-sandbox.doku.com'.$path) {
            return false;
        }
        $return = $wallet === 'dana' ? $request['urlParam'] : $request['urlParams'][0];
        $signature = base64_encode(hash_hmac('sha512', 'POST:'.$path.':test-token:'.hash('sha256', $request->body()).':'.$request->header('X-TIMESTAMP')[0], 'test-only-secret', true));

        return $request['additionalInfo']['channel'] === $channel && $request['amount'] === ['value' => '20000.00', 'currency' => 'IDR']
            && $return === ['url' => 'https://app.example.com/payment/return', 'type' => 'PAY_RETURN', 'isDeepLink' => 'N']
            && $request->header('Authorization') === ['Bearer test-token'] && $request->header('X-PARTNER-ID') === ['MCH-TEST']
            && hash_equals($signature, $request->header('X-SIGNATURE')[0])
            && ! $request->hasHeader('CHANNEL-ID') && preg_match('/^\d+$/', $request->header('X-EXTERNAL-ID')[0]) === 1
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+07:00$/', $request['validUpTo']) === 1;
    });
})->with([
    'dana' => ['dana', '/direct-debit/core/v1/debit/payment-host-to-host', 'EMONEY_DANA_SNAP'],
    'shopeepay' => ['shopeepay', WALLET_PAYMENT_PATH, 'EMONEY_SHOPEE_PAY_SNAP'],
]);

it('issues one provider payment for duplicate and parallel-style requests', function () {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host');

    $first = walletStart($owner, $booking, 'dana')->assertOk()->json('data');
    expect(walletStart($owner, $booking, 'dana')->json('data'))->toBe($first)
        ->and(walletStart($owner, $booking, 'dana', 'another-key')->json('data.attempt_guid'))->toBe($first['attempt_guid'])
        ->and(walletStart($owner, $booking, 'shopeepay', 'switch-channel')->json('data.attempt_guid'))->toBe($first['attempt_guid']);
    $this->assertDatabaseCount('payment_attempts', 1);
    Http::assertSentCount(2);
});

it('keeps a timed-out create unknown and never recreates it', function () {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host', fn () => throw new ConnectionException('timeout'));

    $first = walletStart($owner, $booking, 'dana')->assertOk()->json('data');
    expect($first)->toMatchArray(['status' => 'unknown', 'reason' => 'provider_timeout'])->and($first['instruction'])->toBe(['presentation_type' => 'unavailable']);
    expect(walletStart($owner, $booking, 'dana', 'retry')->json('data.attempt_guid'))->toBe($first['attempt_guid']);
    Http::assertSentCount(1); // Only the token request is recorded; the payment call threw once and was not repeated.
});

it('refuses redirect URLs that are unsafe or off the operator allowlist', function (string $redirect) {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host', fn (ClientRequest $request) => Http::response([
        'responseCode' => '2005400', 'responseMessage' => 'Successful', 'webRedirectUrl' => $redirect, 'partnerReferenceNo' => $request['partnerReferenceNo']]));

    $data = walletStart($owner, $booking, 'dana')->assertOk()->json('data');

    expect($data)->toMatchArray(['status' => 'unknown', 'reason' => 'provider_response_mismatch'])
        ->and($data['instruction'])->toBe(['presentation_type' => 'unavailable']);
})->with([
    'plain http' => 'http://pay.wallet-fixture.example.com/checkout',
    'other host' => 'https://evil.example.net/checkout',
    'userinfo' => 'https://user:pw@pay.wallet-fixture.example.com/checkout',
    'odd port' => 'https://pay.wallet-fixture.example.com:8443/checkout',
    'script' => 'javascript:alert(1)',
    'whitespace' => 'https://pay.wallet-fixture.example.com/a b',
]);

it('treats a mismatched or failed create response as unresolved', function (array $response) {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host', fn (ClientRequest $request) => Http::response($response + ['partnerReferenceNo' => $request['partnerReferenceNo']]));

    walletStart($owner, $booking, 'dana')->assertOk()->assertJsonPath('data.status', 'unknown');
})->with([
    'provider error code' => [['responseCode' => '4005401', 'webRedirectUrl' => WALLET_REDIRECT]],
    'missing redirect' => [['responseCode' => '2005400']],
    'wrong reference' => [['responseCode' => '2005400', 'webRedirectUrl' => WALLET_REDIRECT, 'partnerReferenceNo' => 'someone-else']],
]);

it('keeps ShopeePay and DANA unavailable until their merchant values are valid', function (string $key, mixed $value, string $code) {
    config([$key => $value]);

    expect(app(DokuChannelRegistry::class)->reason($code))->toBe('doku_not_configured');
    expect(app(DokuChannelRegistry::class)->reason($code === 'ewallet_dana' ? 'ewallet_shopeepay' : 'ewallet_dana'))
        ->toBe($key === 'doku.ewallet.shopeepay_payment_path' ? null : 'doku_not_configured');
})->with([
    'shopeepay path unset' => ['doku.ewallet.shopeepay_payment_path', '', 'ewallet_shopeepay'],
    'shopeepay path guessed' => ['doku.ewallet.shopeepay_payment_path', '/direct-debit/core/v3/debit/payment-host-to-host', 'ewallet_shopeepay'],
    'no return url' => ['doku.ewallet.return_url', '', 'ewallet_dana'],
    'http return url' => ['doku.ewallet.return_url', 'http://app.example.com/return', 'ewallet_dana'],
    'no redirect hosts' => ['doku.ewallet.redirect_hosts', [], 'ewallet_dana'],
]);

it('confirms the booking once only from an authenticated paid status query', function () {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host');
    $attempt = walletStart($owner, $booking, 'dana')->json('data.attempt_guid');

    walletReconcile($attempt); // Pending: the customer has not paid yet.
    expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->not->toBe('confirmed')
        ->and(DB::table('payment_attempts')->where('guid', $attempt)->value('status'))->toBe('pending');

    walletSetStatus(walletPaidStatus());
    walletReconcile($attempt);
    walletReconcile($attempt);

    expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBe('confirmed')
        ->and(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'confirmed')->count())->toBe(1)
        ->and(DB::table('payment_events')->where('event_type', 'capture')->where('disposition', 'applied')->count())->toBe(1);
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/payment-intent', bearer($owner))->assertOk()->assertJsonPath('data.payment_status', 'paid');
});

it('never settles from a wallet status the contract does not define', function (array $override) {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host');
    $attempt = walletStart($owner, $booking, 'dana')->json('data.attempt_guid');
    walletSetStatus(walletPaidStatus($override));

    walletReconcile($attempt);

    expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->not->toBe('confirmed')
        ->and(DB::table('payment_attempts')->where('guid', $attempt)->value('status'))->toBe('unknown')
        ->and(DB::table('payment_events')->count())->toBe(0);
})->with([
    'refunded' => [['latestTransactionStatus' => '04']],
    'not found' => [['latestTransactionStatus' => '07']],
    'undefined status' => [['latestTransactionStatus' => '99']],
    'provider error' => [['responseCode' => '5005500']],
    'other order' => [['originalPartnerReferenceNo' => 'someone-else']],
    'paid without time' => [['paidTime' => 'yesterday']],
    'paid without amount' => [['transAmount' => []]],
]);

it('sends a captured amount mismatch to review instead of confirming', function () {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host');
    $attempt = walletStart($owner, $booking, 'dana')->json('data.attempt_guid');
    walletSetStatus(walletPaidStatus(['transAmount' => ['value' => '1000.00', 'currency' => 'IDR']]));

    walletReconcile($attempt);

    expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->not->toBe('confirmed')
        ->and(DB::table('payment_intents')->value('status'))->toBe('needs_review');
});

it('does not confirm a payment made after the deadline', function () {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host');
    $attempt = walletStart($owner, $booking, 'dana')->json('data.attempt_guid');
    $late = CarbonImmutable::parse(DB::table('bookings')->where('guid', $booking->guid)->value('payment_expires_at'), 'UTC')->addMinute();
    walletSetStatus(walletPaidStatus(['paidTime' => $late->toIso8601String()]));
    $this->travelTo($late->addMinute());

    walletReconcile($attempt);

    expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->not->toBe('confirmed');
});

it('returns a failed wallet payment to a payable intent', function () {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host');
    $attempt = walletStart($owner, $booking, 'dana')->json('data.attempt_guid');
    walletSetStatus(walletPaidStatus(['latestTransactionStatus' => '06']));

    walletReconcile($attempt);

    expect(DB::table('payment_attempts')->where('guid', $attempt)->value('status'))->toBe('failed')
        ->and(DB::table('payment_intents')->value('status'))->toBe('requires_payment')
        ->and(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->not->toBe('confirmed');
});

it('rejects a provider outcome declared for another channel', function () {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host');
    $attempt = walletStart($owner, $booking, 'dana')->json('data.attempt_guid');
    $row = DB::table('payment_attempts')->where('guid', $attempt)->first();

    expect(fn () => app(DokuPaymentService::class)->receive(['status' => 'paid', 'method' => 'ewallet', 'channel' => 'shopeepay',
        'merchant_reference' => $row->merchant_reference, 'provider_reference' => 'WREF123', 'amount' => 20000, 'currency' => 'IDR',
        'occurred_at' => now()->toIso8601String(), 'event_key' => 'mismatch', 'payload_hash' => hash('sha256', 'mismatch')]))
        ->toThrow(BookingException::class);
    expect(DB::table('payment_events')->count())->toBe(0);
});

it('does not accept an unauthenticated e-wallet notification on the VA webhook', function () {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host');
    $attempt = walletStart($owner, $booking, 'dana')->json('data.attempt_guid');
    $merchantReference = DB::table('payment_attempts')->where('guid', $attempt)->value('merchant_reference');

    $this->postJson('/api/v1/webhooks/doku/va', ['originalPartnerReferenceNo' => $merchantReference, 'latestTransactionStatus' => '00',
        'amount' => ['value' => '20000.00', 'currency' => 'IDR'], 'additionalInfo' => ['channel' => 'EMONEY_DANA_SNAP']], [
            'X-PARTNER-ID' => 'MCH-TEST', 'X-TIMESTAMP' => now()->toIso8601String(), 'X-SIGNATURE' => 'forged', 'X-EXTERNAL-ID' => '1',
            'Authorization' => 'Bearer x', 'CHANNEL-ID' => 'DH'])->assertStatus(422);

    expect(DB::table('payment_events')->count())->toBe(0)
        ->and(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->not->toBe('confirmed');
});

function walletRefresh(object $user, string $bookingGuid, string $attemptGuid)
{
    return test()->postJson('/api/v1/bookings/'.$bookingGuid.'/payment-attempts/'.$attemptGuid.'/refresh', [], bearer($user));
}

function walletStatusCalls(): int
{
    return Http::recorded(fn (ClientRequest $request) => $request->url() === 'https://api-sandbox.doku.com/orders/v1.0/debit/status')->count();
}

it('does not settle anything when the customer lands on the return page', function () {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host');
    $attempt = walletStart($owner, $booking, 'dana')->json('data.attempt_guid');
    $before = [DB::table('payment_attempts')->where('guid', $attempt)->first(), DB::table('bookings')->where('guid', $booking->guid)->value('status')];

    $this->get('/payment/return?result=success')->assertOk();

    expect(walletStatusCalls())->toBe(0)
        ->and(DB::table('payment_attempts')->where('guid', $attempt)->first())->toEqual($before[0])
        ->and(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBe($before[1])->not->toBe('confirmed')
        ->and(DB::table('payment_events')->count())->toBe(0);
});

it('confirms a return only through the authenticated status inquiry and reports it to the app', function () {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host');
    $attempt = walletStart($owner, $booking, 'dana')->json('data.attempt_guid');
    walletSetStatus(walletPaidStatus());

    $data = walletRefresh($owner, $booking->guid, $attempt)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');

    expect($data)->toMatchArray(['booking_guid' => $booking->guid, 'booking_status' => 'confirmed', 'payment_status' => 'paid', 'status' => 'paid'])
        ->and($data['last_attempt'])->toMatchArray(['attempt_guid' => $attempt, 'status' => 'paid', 'channel' => 'dana'])
        ->and($data['instruction'])->toBe(['presentation_type' => 'unavailable'])
        ->and(walletStatusCalls())->toBe(1)
        ->and(DB::table('payment_events')->where('event_type', 'capture')->where('disposition', 'applied')->count())->toBe(1);

    $tickets = $this->getJson('/api/v1/bookings/'.$booking->guid.'/tickets', bearer($owner))->assertOk()
        ->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.payment_provider', 'doku')
        ->assertJsonPath('data.payment_environment', 'sandbox')->assertJsonPath('data.ticket_state', 'available')
        ->assertJsonCount(2, 'data.tickets')->assertJsonPath('data.tickets.0.qr_available', true)
        ->assertJsonPath('data.tickets.1.qr_available', true)->json('data.tickets');

    expect(DB::table('booking_tickets')->where('booking_guid', $booking->guid)->whereNotNull('qr_token_hash')->count())->toBe(2);
    $this->get('/api/v1/bookings/'.$booking->guid.'/tickets/'.$tickets[0]['guid'].'/qr', bearer($owner))
        ->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
});

it('keeps the payment pending while the wallet has not finished and lets the app reopen it', function () {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host');
    $attempt = walletStart($owner, $booking, 'dana')->json('data.attempt_guid');

    $data = walletRefresh($owner, $booking->guid, $attempt)->assertOk()->json('data');

    expect($data['last_attempt']['status'])->toBe('pending')->and($data['payment_status'])->toBe('unverified')
        ->and($data['instruction'])->toMatchArray(['presentation_type' => 'ewallet_redirect', 'redirect_url' => WALLET_REDIRECT])
        ->and(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->not->toBe('confirmed');
});

it('returns a cancelled or failed wallet payment to a payable intent on refresh', function (string $providerStatus) {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host');
    $attempt = walletStart($owner, $booking, 'dana')->json('data.attempt_guid');
    walletSetStatus(fn (ClientRequest $request) => Http::response([
        'responseCode' => '2005500', 'originalPartnerReferenceNo' => $request['originalPartnerReferenceNo'], 'originalReferenceNo' => 'WREF123',
        'serviceCode' => '55', 'latestTransactionStatus' => $providerStatus]));

    $data = walletRefresh($owner, $booking->guid, $attempt)->assertOk()->json('data');

    expect($data['last_attempt']['status'])->toBe('failed')->and($data['status'])->toBe('requires_payment')->and($data['booking_status'])->not->toBe('confirmed')
        ->and($data['instruction'])->toBe(['presentation_type' => 'unavailable']);
})->with(['cancelled (05)' => '05', 'failed (06)' => '06']);

it('keeps an uncertain provider error unconfirmed and never creates a second payment', function () {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host');
    $attempt = walletStart($owner, $booking, 'dana')->json('data.attempt_guid');
    walletSetStatus(fn () => throw new ConnectionException('timeout'));

    $data = walletRefresh($owner, $booking->guid, $attempt)->assertOk()->json('data');

    expect($data['last_attempt']['status'])->toBe('unknown')->and($data['booking_status'])->not->toBe('confirmed')
        ->and(DB::table('payment_attempts')->count())->toBe(1)
        ->and(Http::recorded(fn (ClientRequest $request) => str_contains($request->url(), 'payment-host-to-host'))->count())->toBe(1);
});

it('queries the provider once for rapid repeated refreshes such as app resume', function () {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host');
    $attempt = walletStart($owner, $booking, 'dana')->json('data.attempt_guid');

    foreach (range(1, 3) as $ignored) {
        walletRefresh($owner, $booking->guid, $attempt)->assertOk();
    }
    expect(walletStatusCalls())->toBe(1);

    $this->travel(15)->seconds();
    walletRefresh($owner, $booking->guid, $attempt)->assertOk();
    expect(walletStatusCalls())->toBe(1);

    $this->travel(45)->seconds();
    walletRefresh($owner, $booking->guid, $attempt)->assertOk();
    expect(walletStatusCalls())->toBe(2);
});

it('is idempotent after payment: further refreshes neither query the provider nor capture again', function () {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host');
    $attempt = walletStart($owner, $booking, 'dana')->json('data.attempt_guid');
    walletSetStatus(walletPaidStatus());
    walletRefresh($owner, $booking->guid, $attempt)->assertOk();
    $this->travel(15)->seconds();

    walletRefresh($owner, $booking->guid, $attempt)->assertOk()->assertJsonPath('data.payment_status', 'paid');

    expect(walletStatusCalls())->toBe(1)->and(DB::table('payment_events')->where('event_type', 'capture')->count())->toBe(1)
        ->and(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'confirmed')->count())->toBe(1);
});

it('hides attempts from other visitors, other bookings and unauthenticated callers', function () {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host');
    $attempt = walletStart($owner, $booking, 'dana')->json('data.attempt_guid');
    $stranger = makeUser();

    walletRefresh($stranger, $booking->guid, $attempt)->assertNotFound()->assertJsonPath('code', 'not_found')->assertHeader('Cache-Control', 'no-store, private');
    walletRefresh($owner, $booking->guid, (string) Str::uuid())->assertNotFound();
    $this->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts/'.$attempt.'/refresh')->assertUnauthorized();
    $this->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts/not-a-uuid/refresh', [], bearer($owner))->assertNotFound();
    expect(walletStatusCalls())->toBe(0);
});

/** Collects the structured DOKU failure records emitted during the test. */
function walletDiagnostics(): ArrayObject
{
    $records = new ArrayObject;
    Log::listen(function ($event) use ($records) {
        if (($event->context['event'] ?? null) === 'doku.provider_request_failed') {
            $records->append($event->context);
        }
    });

    return $records;
}

function walletPaymentSent(): bool
{
    return Http::recorded(fn (ClientRequest $request) => str_contains($request->url(), 'payment-host-to-host'))->isNotEmpty();
}

it('records the phase, HTTP status and safe code when the payment create returns an HTTP error', function (int $status) {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host', fn () => Http::response(['responseCode' => $status.'5400', 'responseMessage' => 'Rejected'], $status));
    $records = walletDiagnostics();

    $data = walletStart($owner, $booking, 'dana')->assertOk()->json('data');

    expect($data)->toMatchArray(['status' => 'unknown', 'reason' => 'provider_unavailable']);
    expect($records)->toHaveCount(1)
        ->and($records[0])->toMatchArray(['attempt_guid' => $data['attempt_guid'], 'provider' => 'doku', 'environment' => 'sandbox', 'channel' => 'dana',
            'phase' => 'payment_create', 'http_status' => $status, 'provider_response_code' => $status.'5400',
            'diagnostic_code' => 'doku_payment_http_error', 'outcome_certainty' => 'unknown']);
})->with([400, 401, 422, 429, 503]);

it('treats a DANA 5005400 general error as a final failure and never reconciles it', function () {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host', fn () => Http::response(['responseCode' => '5005400', 'responseMessage' => 'General Error'], 500));
    $records = walletDiagnostics();

    $data = walletStart($owner, $booking, 'dana')->assertOk()->json('data');

    expect($data)->toMatchArray(['status' => 'failed', 'reason' => 'provider_general_error', 'booking_status' => 'awaiting_payment'])
        ->and($data['instruction'])->toBe(['presentation_type' => 'unavailable']);
    expect($records)->toHaveCount(1)->and($records[0])->toMatchArray(['http_status' => 500, 'provider_response_code' => '5005400', 'diagnostic_code' => 'doku_payment_http_error']);
    $attempt = DB::table('payment_attempts')->where('guid', $data['attempt_guid'])->first();
    expect($attempt->status)->toBe('failed')->and($attempt->next_reconcile_at)->toBeNull()->and($attempt->finished_at)->not->toBeNull()
        ->and($attempt->last_error_code)->toBe('provider_general_error');
    expect(DB::table('payment_intents')->value('status'))->toBe('requires_payment')
        ->and(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBe('awaiting_payment')
        ->and(DB::table('booking_tickets')->whereNotNull('qr_token_hash')->count())->toBe(0)
        ->and(DB::table('payment_events')->count())->toBe(0);

    walletReconcile($attempt->guid);
    walletRefresh($owner, $booking->guid, $attempt->guid);
    expect(walletStatusCalls())->toBe(0)->and(DB::table('payment_attempts')->where('guid', $attempt->guid)->value('status'))->toBe('failed');
});

it('keeps every other DANA create failure unknown so it can still be reconciled', function (int $status, string $code) {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host', fn () => Http::response(['responseCode' => $code, 'responseMessage' => 'Retry'], $status));

    $data = walletStart($owner, $booking, 'dana')->assertOk()->json('data');

    expect($data)->toMatchArray(['status' => 'unknown', 'reason' => 'provider_unavailable', 'booking_status' => 'awaiting_payment']);
    expect(DB::table('payment_attempts')->where('guid', $data['attempt_guid'])->value('next_reconcile_at'))->not->toBeNull()
        ->and(DB::table('booking_tickets')->whereNotNull('qr_token_hash')->count())->toBe(0);
})->with([
    '5005401 retryable' => [500, '5005401'],
    'rate limited' => [429, '4295400'],
    '5005400 with another status' => [503, '5005400'],
]);

it('does not turn a ShopeePay 5005400 into a final failure', function () {
    config(['doku.ewallet.shopeepay_payment_path' => WALLET_PAYMENT_PATH]);
    [$owner, $booking] = walletBooking();
    walletFake(WALLET_PAYMENT_PATH, fn () => Http::response(['responseCode' => '5005400', 'responseMessage' => 'General Error'], 500));

    walletStart($owner, $booking, 'shopeepay')->assertOk()->assertJsonPath('data.status', 'unknown');
});

/** Collects the sanitized create-failure snapshots written during the test. */
function walletSnapshots(): ArrayObject
{
    $records = new ArrayObject;
    Log::listen(function ($event) use ($records) {
        if (($event->context['event'] ?? null) === 'doku.create_failure_snapshot') {
            $records->append($event->context);
        }
    });

    return $records;
}

it('stores a sanitized snapshot of a failed create with the full body and no credentials', function () {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host', fn (ClientRequest $request) => Http::response([
        'responseCode' => '5005400', 'responseMessage' => 'General Error', 'echo' => ['auth' => 'Bearer test-token', 'sig' => $request->header('X-SIGNATURE')[0],
            'note' => 'key test-only-secret', 'accessToken' => 'other-token-value', 'webRedirectUrl' => 'https://pay.example.net/x?token=leaked-query'],
    ], 500));
    $snapshots = walletSnapshots();

    $data = walletStart($owner, $booking, 'dana')->assertOk()->json('data');

    $attempt = DB::table('payment_attempts')->where('guid', $data['attempt_guid'])->first();
    expect($snapshots)->toHaveCount(1);
    $snapshot = $snapshots[0];
    expect($snapshot)->toMatchArray(['attempt_guid' => $data['attempt_guid'], 'channel' => 'dana', 'diagnostic_code' => 'doku_payment_http_error'])
        ->and($snapshot['request'])->toMatchArray(['method' => 'POST', 'path' => '/direct-debit/core/v1/debit/payment-host-to-host'])
        ->and($snapshot['request']['headers']['X-EXTERNAL-ID'])->toBe($attempt->provider_request_id)
        ->and($snapshot['request']['headers']['X-TIMESTAMP'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/')
        ->and($snapshot['request']['headers']['X-PARTNER-ID'])->toBe('MCH-TEST')
        ->and($snapshot['request']['headers']['X-SIGNATURE'])->toBe('[REDACTED]')
        ->and($snapshot['request']['headers']['Authorization'])->toBe('[REDACTED]')
        ->and($snapshot['request']['body']['partnerReferenceNo'])->toBe($attempt->merchant_reference)
        ->and($snapshot['request']['body']['urlParam'])->toBe(['url' => 'https://app.example.com/payment/return', 'type' => 'PAY_RETURN', 'isDeepLink' => 'N'])
        ->and($snapshot['request']['body']['amount'])->toBe(['value' => '20000.00', 'currency' => 'IDR'])
        ->and($snapshot['response']['status'])->toBe(500)
        ->and($snapshot['response']['body']['responseCode'])->toBe('5005400')
        ->and($snapshot['response']['body']['responseMessage'])->toBe('General Error');
    $signature = Http::recorded(fn (ClientRequest $request) => str_contains($request->url(), 'payment-host-to-host'))->first()[0]->header('X-SIGNATURE')[0];
    expect(json_encode($snapshot))->not->toContain('test-token')->not->toContain('test-only-secret')->not->toContain($signature)
        ->not->toContain('other-token-value')->not->toContain('leaked-query');
});

it('stores a snapshot for timeouts and for soft failures', function (Closure $create, string $code, bool $hasResponse) {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host', $create);
    $snapshots = walletSnapshots();

    walletStart($owner, $booking, 'dana')->assertOk();

    expect($snapshots)->toHaveCount(1)->and($snapshots[0]['diagnostic_code'])->toBe($code)
        ->and($snapshots[0]['response'] !== null)->toBe($hasResponse)
        ->and(json_encode($snapshots[0]))->not->toContain('test-token')->not->toContain('test-only-secret');
})->with([
    'timeout' => [fn () => throw new ConnectionException('timeout'), 'doku_payment_transport_timeout', false],
    'rejected code' => [fn () => Http::response(['responseCode' => '4005401', 'responseMessage' => 'Invalid']), 'doku_payment_not_accepted', true],
    'html body' => [fn () => Http::response('<html>gateway test-only-secret</html>', 200), 'doku_payment_response_invalid', true],
]);

it('writes no snapshot when the create succeeds', function () {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host');
    $snapshots = walletSnapshots();

    walletStart($owner, $booking, 'dana')->assertOk()->assertJsonPath('data.status', 'pending');

    expect($snapshots)->toHaveCount(0);
});

it('classifies token failures as never having dispatched the payment', function (mixed $token, string $code, ?int $status) {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host', token: $token);
    $records = walletDiagnostics();

    $data = walletStart($owner, $booking, 'dana')->assertOk()->json('data');

    expect($data)->toMatchArray(['status' => 'unknown', 'reason' => 'provider_unavailable'])->and(walletPaymentSent())->toBeFalse();
    expect($records)->toHaveCount(1)
        ->and($records[0])->toMatchArray(['phase' => 'b2b_token', 'diagnostic_code' => $code, 'http_status' => $status, 'outcome_certainty' => 'not_issued']);
})->with([
    'rejected credentials' => [fn () => Http::response(['responseCode' => '4017300', 'responseMessage' => 'Unauthorized'], 401), 'doku_token_http_error', 401],
    'provider outage' => [fn () => Http::response('upstream down', 500), 'doku_token_http_error', 500],
    'no usable token' => [fn () => Http::response(['responseCode' => '2007300', 'tokenType' => 'Bearer']), 'doku_token_response_invalid', 200],
]);

it('separates transport timeouts from provider responses', function (string $stage, string $code, string $phase, string $outcome) {
    [$owner, $booking] = walletBooking();
    $timeout = fn () => throw new ConnectionException('timeout');
    $stage === 'token'
        ? walletFake('/direct-debit/core/v1/debit/payment-host-to-host', token: $timeout)
        : walletFake('/direct-debit/core/v1/debit/payment-host-to-host', $timeout);
    $records = walletDiagnostics();

    walletStart($owner, $booking, 'dana')->assertOk()->assertJsonPath('data.reason', 'provider_timeout');

    expect($records)->toHaveCount(1)
        ->and($records[0])->toMatchArray(['phase' => $phase, 'diagnostic_code' => $code, 'exception_class' => ConnectionException::class, 'request_sent' => 'unknown', 'outcome_certainty' => $outcome]);
})->with([
    'token' => ['token', 'doku_token_transport_error', 'b2b_token', 'not_issued'],
    'payment' => ['payment', 'doku_payment_transport_timeout', 'payment_create', 'unknown'],
]);

it('records invalid, rejected and redirect-blocked create responses without changing their outcome', function (Closure $create, string $reason, string $code, ?string $providerCode) {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host', $create);
    $records = walletDiagnostics();

    walletStart($owner, $booking, 'dana')->assertOk()->assertJsonPath('data.status', 'unknown')->assertJsonPath('data.reason', $reason);

    expect($records)->toHaveCount(1)->and($records[0])->toMatchArray(['phase' => 'payment_create', 'diagnostic_code' => $code, 'provider_response_code' => $providerCode]);
})->with([
    'html body' => [fn () => Http::response('<html>gateway</html>', 200), 'provider_unavailable', 'doku_payment_response_invalid', null],
    'non-success code' => [fn () => Http::response(['responseCode' => '4005401', 'responseMessage' => 'Invalid']), 'provider_create_unresolved', 'doku_payment_not_accepted', '4005401'],
    'off-allowlist redirect' => [fn () => Http::response(['responseCode' => '2005400', 'webRedirectUrl' => 'https://evil.example.net/pay']), 'provider_response_mismatch', 'doku_redirect_host_rejected', '2005400'],
]);

it('never logs provider content, credentials or redirect URLs', function () {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host', fn () => Http::response([
        'responseCode' => 'ERR <script>pin=123456</script>', 'responseMessage' => 'card 4111111111111111 pin 123456',
        'webRedirectUrl' => 'https://evil.example.net/pay?token=leaked-redirect-token'], 422));
    $records = walletDiagnostics();

    walletStart($owner, $booking, 'dana')->assertOk();

    $logged = json_encode($records->getArrayCopy());
    expect($records[0]['provider_response_code'])->toBeNull()
        ->and($logged)->not->toContain('4111111111111111')->not->toContain('123456')->not->toContain('leaked-redirect-token')
        ->not->toContain('test-token')->not->toContain('test-only-secret')->not->toContain('evil.example.net')
        ->and(array_keys($records[0]))->toEqualCanonicalizing(['event', 'attempt_guid', 'provider', 'environment', 'channel', 'phase', 'http_status',
            'provider_response_code', 'diagnostic_code', 'exception_class', 'duration_ms', 'request_sent', 'outcome_certainty']);
});

it('reports an unexpected internal fault by class only and still keeps the attempt unknown', function () {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host', fn () => throw new TypeError('secret-internal-detail'));
    $records = walletDiagnostics();

    walletStart($owner, $booking, 'dana')->assertOk()->assertJsonPath('data.status', 'unknown')->assertJsonPath('data.reason', 'provider_unavailable');

    expect($records)->toHaveCount(1)
        ->and($records[0])->toMatchArray(['phase' => 'internal', 'diagnostic_code' => 'doku_internal_exception', 'exception_class' => TypeError::class])
        ->and(json_encode($records->getArrayCopy()))->not->toContain('secret-internal-detail');
});

it('emits no diagnostic record when the create succeeds', function () {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host');
    $records = walletDiagnostics();

    walletStart($owner, $booking, 'dana')->assertOk()->assertJsonPath('data.status', 'pending');

    expect($records)->toHaveCount(0);
});

it('rejects a success code from another service when creating a wallet payment', function () {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host', fn (ClientRequest $request) => Http::response([
        'responseCode' => '2005500', 'partnerReferenceNo' => $request['partnerReferenceNo'], 'webRedirectUrl' => WALLET_REDIRECT]));

    walletStart($owner, $booking, 'dana')->assertOk()->assertJsonPath('data.status', 'unknown')
        ->assertJsonPath('data.reason', 'provider_create_unresolved');

    expect(DB::table('payment_events')->count())->toBe(0);
});

it('sends the original request and provider references when querying a wallet payment', function () {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host', fn (ClientRequest $request) => Http::response([
        'responseCode' => '2005400', 'referenceNo' => 'WREF123', 'partnerReferenceNo' => $request['partnerReferenceNo'], 'webRedirectUrl' => WALLET_REDIRECT]));
    $attempt = walletStart($owner, $booking, 'dana')->json('data.attempt_guid');
    $row = DB::table('payment_attempts')->where('guid', $attempt)->first();

    walletReconcile($attempt);

    Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/debit/status')
        && $request['originalPartnerReferenceNo'] === $row->merchant_reference
        && ($request->data()['originalExternalId'] ?? null) === $row->provider_request_id
        && ($request->data()['originalReferenceNo'] ?? null) === 'WREF123' && $request['serviceCode'] === '55');
});

it('does not settle wallet inquiry evidence from a different service or reference', function (array $override) {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host', fn (ClientRequest $request) => Http::response([
        'responseCode' => '2005400', 'referenceNo' => 'WREF123', 'partnerReferenceNo' => $request['partnerReferenceNo'], 'webRedirectUrl' => WALLET_REDIRECT]));
    $attempt = walletStart($owner, $booking, 'dana')->json('data.attempt_guid');
    walletSetStatus(walletPaidStatus($override));

    walletReconcile($attempt);

    expect(DB::table('payment_attempts')->where('guid', $attempt)->value('status'))->toBe('unknown');
    expect(DB::table('payment_events')->count())->toBe(0);
    expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->not->toBe('confirmed');
})->with([
    'create service success code' => [['responseCode' => '2005400']],
    'different service' => [['serviceCode' => '54']],
    'different provider reference' => [['originalReferenceNo' => 'WREF-OTHER']],
    'different external request' => [['originalExternalId' => '123']],
]);

it('keeps reconciling an issued wallet payment after issuance is disabled', function () {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host');
    $attempt = walletStart($owner, $booking, 'dana')->json('data.attempt_guid');
    walletSetStatus(walletPaidStatus());
    config(['doku.enabled' => false, 'doku.channels' => [], 'doku.ewallet' => []]);

    walletReconcile($attempt);

    expect(DB::table('payment_attempts')->where('guid', $attempt)->value('status'))->toBe('paid');
    expect(DB::table('payment_events')->where('event_type', 'capture')->where('disposition', 'applied')->count())->toBe(1);
    expect(walletStatusCalls())->toBe(1);
});

it('accepts the documented ShopeePay inquiry service code 54', function (string $providerStatus, string $attemptStatus, string $bookingStatus, int $captures) {
    [$owner, $booking] = walletBooking();
    walletFake(WALLET_PAYMENT_PATH);
    $attempt = walletStart($owner, $booking, 'shopeepay')->json('data.attempt_guid');
    walletSetStatus(walletPaidStatus(['serviceCode' => '54', 'latestTransactionStatus' => $providerStatus]));

    walletReconcile($attempt);

    expect(DB::table('payment_attempts')->where('guid', $attempt)->value('status'))->toBe($attemptStatus);
    expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBe($bookingStatus);
    expect(DB::table('payment_events')->where('event_type', 'capture')->where('disposition', 'applied')->count())->toBe($captures);
})->with([
    'pending' => ['03', 'pending', 'awaiting_payment', 0],
    'paid with timezone aware evidence' => ['00', 'paid', 'confirmed', 1],
]);

it('keeps a not-found inquiry unknown without issuing another wallet payment', function () {
    [$owner, $booking] = walletBooking();
    walletFake('/direct-debit/core/v1/debit/payment-host-to-host');
    $attempt = walletStart($owner, $booking, 'dana')->json('data.attempt_guid');
    walletSetStatus(fn () => Http::response(['responseCode' => '4045501', 'responseMessage' => 'Transaction Not Found'], 404));

    walletReconcile($attempt);

    expect(DB::table('payment_attempts')->where('guid', $attempt)->value('status'))->toBe('unknown');
    expect(DB::table('payment_events')->count())->toBe(0);
    expect(Http::recorded(fn (ClientRequest $request) => str_contains($request->url(), 'payment-host-to-host'))->count())->toBe(1);
});
