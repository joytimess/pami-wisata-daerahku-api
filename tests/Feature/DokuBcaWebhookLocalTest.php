<?php

use App\Services\BookingService;
use App\Services\Doku\DokuChannelRegistry;
use App\Services\Doku\DokuClient;
use App\Services\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * LOCAL SIMULATION ONLY. Every notification here is synthetic and signed by this test with a throwaway secret and
 * token. Nothing proves that DOKU Sandbox or Production sends this shape: no real BCA notification has ever been
 * received (docs/prd/DOKU-RUNBOOK.md, "BCA VA SNAP v1.1 DGPC"). The CHANNEL-ID below is a test value, not a DOKU value.
 *
 * The body follows the BI-SNAP VA payment-flag notification fields the verifier already enforces (partnerServiceId
 * padded to 8 with spaces, customerNo, virtualAccountNo = padded partner + customerNo, trxId, paymentRequestId,
 * paidAmount, virtualAccountTrxType C, trxDateTime, additionalInfo.channel) with the real BCA geometry observed on the
 * Sandbox Create/Status calls (BIN 19008, customer prefix 9, 11-digit customerNo, 16-digit VA).
 *
 * The suite runs on the testing environment (in-memory SQLite), never sends HTTP (Http::preventStrayRequests; the
 * Create VA call is faked) and widens DOKU_VERIFIED_CHANNELS in-process only. No .env value is read or changed.
 */
const BCA_LOCAL_PARTNER = '19008';
const BCA_LOCAL_PREFIX = '9';
const BCA_LOCAL_CUSTOMER = '90000000001';
const BCA_LOCAL_NOTIFY_CHANNEL_ID = 'TESTBCANOTIFY';
const BCA_LOCAL_SECRET = 'test-only-secret';
const BCA_LOCAL_TOKEN = 'test-only-token';

beforeEach(function () {
    app()->instance(DokuChannelRegistry::class, new class extends DokuChannelRegistry
    {
        protected function issuanceScope(): array
        {
            return array_keys($this->all());
        }
    });
    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00:00', 'UTC'));
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $this->bcaKey = tempnam(sys_get_temp_dir(), 'doku-bca-local-');
    file_put_contents($this->bcaKey, $pem);
    config(['operations.demo_payments_enabled' => false, 'app.debug' => false, 'doku.enabled' => true, 'doku.environment' => 'sandbox',
        'doku.client_id' => 'MCH-TEST', 'doku.secret_key' => BCA_LOCAL_SECRET, 'doku.merchant_private_key_path' => $this->bcaKey,
        'doku.merchant_private_key_passphrase' => '', 'doku.partner_service_id' => '98829172', 'doku.customer_prefix' => '3',
        'doku.channels' => ['va_bni', 'va_bca'], 'doku.verified_channels' => ['va_bni', 'va_bca'],
        'doku.va_banks.bca' => ['partner_service_id' => BCA_LOCAL_PARTNER, 'customer_prefix' => BCA_LOCAL_PREFIX, 'notification_channel_id' => BCA_LOCAL_NOTIFY_CHANNEL_ID],
        'doku.webhook_url' => 'https://sandbox.example.com/api/v1/webhooks/doku/va']);
    Http::preventStrayRequests();
});

afterEach(function () {
    unlink($this->bcaKey);
});

function bcaLocalBooking(int $quantity = 2): array
{
    [$destination, $date] = seedDestination(capacity: 5, price: 10000, days: 5);
    $owner = makeUser();
    $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date, $quantity) + ['idempotency_key' => (string) Str::uuid()]);
    $booking = app(BookingService::class)->decide(makeUser(2), $booking->guid, true);

    return [$owner, app(TicketService::class)->booking($booking->guid)];
}

/** Create VA answer shaped like the real BCA Sandbox one: padded raw VA, 11-digit customerNo and no additionalInfo.channel. */
function bcaLocalFakeCreate(): void
{
    Http::fake([
        'https://api-sandbox.doku.com/authorization/v1/access-token/b2b' => Http::response(['responseCode' => '2007300', 'accessToken' => 'create-token', 'tokenType' => 'Bearer', 'expiresIn' => 900]),
        'https://api-sandbox.doku.com/virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va' => fn (ClientRequest $request) => Http::response(['responseCode' => '2002700',
            'responseMessage' => 'Successful', 'virtualAccountData' => ['partnerServiceId' => '   '.BCA_LOCAL_PARTNER, 'customerNo' => BCA_LOCAL_CUSTOMER,
                'virtualAccountNo' => '   '.BCA_LOCAL_PARTNER.BCA_LOCAL_CUSTOMER, 'trxId' => $request['trxId'], 'virtualAccountTrxType' => 'C',
                'totalAmount' => $request['totalAmount'], 'expiredDate' => $request['expiredDate'],
                'additionalInfo' => ['howToPayApi' => 'https://example.invalid/bca/how-to-pay-api', 'howToPayPage' => 'https://example.invalid/bca/how-to-pay-page']]]),
    ]);
}

function bcaLocalStart(object $owner, object $booking, string $key = 'bca-local-start'): array
{
    return test()->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts', ['method_code' => 'virtual_account', 'channel' => 'bca'],
        bearer($owner) + ['Idempotency-Key' => $key])->assertOk()->json('data');
}

/**
 * Signs and posts a synthetic BCA notification to the local route.
 *
 * @param  array<string, mixed>  $overrides  Recursive overrides of the notification body.
 * @param  array<string, string>  $headers  Server-var overrides (e.g. HTTP_CHANNEL_ID).
 * @param  array{secret?: string, token?: string, timestamp?: string, pretty?: bool, signed_body?: string}  $sign
 */
function bcaLocalNotify(string $attemptGuid, array $overrides = [], array $headers = [], array $sign = [])
{
    $attempt = DB::table('payment_attempts')->where('guid', $attemptGuid)->first();
    $payload = array_replace_recursive(['partnerServiceId' => '   '.BCA_LOCAL_PARTNER, 'customerNo' => BCA_LOCAL_CUSTOMER,
        'virtualAccountNo' => '   '.BCA_LOCAL_PARTNER.BCA_LOCAL_CUSTOMER, 'virtualAccountName' => 'Test Visitor', 'trxId' => $attempt->merchant_reference,
        'paymentRequestId' => '20261110000000000001', 'paidAmount' => ['value' => $attempt->amount.'.00', 'currency' => 'IDR'], 'virtualAccountTrxType' => 'C',
        'trxDateTime' => now()->toIso8601String(), 'additionalInfo' => ['channel' => 'VIRTUAL_ACCOUNT_BCA']], $overrides);
    foreach ($overrides['_remove'] ?? [] as $path) {
        unset($payload[$path]);
    }
    unset($payload['_remove']);
    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $timestamp = $sign['timestamp'] ?? now()->toIso8601String();
    $signedBody = $sign['signed_body'] ?? $body;
    $token = $sign['token'] ?? BCA_LOCAL_TOKEN;
    $signature = base64_encode(hash_hmac('sha512', 'POST:/api/v1/webhooks/doku/va:'.$token.':'.hash('sha256', $signedBody).':'.$timestamp, $sign['secret'] ?? BCA_LOCAL_SECRET, true));
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PARTNER_ID' => 'MCH-TEST', 'HTTP_CHANNEL_ID' => BCA_LOCAL_NOTIFY_CHANNEL_ID,
        'HTTP_X_EXTERNAL_ID' => '202611100000001', 'HTTP_AUTHORIZATION' => 'Bearer '.BCA_LOCAL_TOKEN, 'HTTP_X_TIMESTAMP' => $timestamp, 'HTTP_X_SIGNATURE' => $signature];
    if ($sign['pretty'] ?? false) {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    return test()->call('POST', '/api/v1/webhooks/doku/va', [], [], [], array_replace($server, $headers), $body);
}

/** @return array<string, mixed> */
function bcaLocalState(string $bookingGuid): array
{
    $intent = DB::table('payment_intents')->where('booking_guid', $bookingGuid)->first();

    return ['booking' => DB::table('bookings')->where('guid', $bookingGuid)->value('status'), 'intent' => $intent?->status,
        'attempts' => DB::table('payment_attempts')->where('payment_intent_guid', $intent?->guid)->pluck('status')->all(),
        'events' => DB::table('payment_events')->orderBy('received_at')->pluck('disposition', 'event_type')->all(),
        'event_count' => DB::table('payment_events')->count(),
        'qr' => DB::table('booking_tickets')->where('booking_guid', $bookingGuid)->whereNotNull('qr_token_hash')->count(),
        'tickets' => DB::table('booking_tickets')->where('booking_guid', $bookingGuid)->orderBy('sequence')->pluck('qr_token_hash')->all(),
        'confirmed_history' => DB::table('booking_status_histories')->where('booking_guid', $bookingGuid)->where('new_status', 'confirmed')->count(),
        'ticket_events' => DB::table('notifications')->where('booking_guid', $bookingGuid)->where('type', 'booking.ticket_issued')->count(),
        'receipts' => DB::table('notifications')->where('booking_guid', $bookingGuid)->where('type', 'booking.payment_receipt')->count()];
}

it('runs only against the isolated testing database and never reaches the network', function () {
    expect(app()->environment())->toBe('testing')
        ->and(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:')
        ->and(config('doku.environment'))->toBe('sandbox');
    [$owner, $booking] = bcaLocalBooking();
    bcaLocalFakeCreate();

    bcaLocalNotify(bcaLocalStart($owner, $booking)['attempt_guid'])->assertOk();

    // Only the faked token + Create VA calls exist; verifying and settling a notification performs no outbound request.
    Http::assertSentCount(2);
});

it('settles a signed BCA notification end to end and issues the tickets', function (bool $padded) {
    [$owner, $booking] = bcaLocalBooking();
    bcaLocalFakeCreate();
    $attempt = bcaLocalStart($owner, $booking);
    $stored = DB::table('payment_attempts')->where('guid', $attempt['attempt_guid'])->first();

    expect($stored->provider_reference)->toBe('1900890000000001')->and($attempt['instruction']['va_number'])->toBe('1900890000000001')
        ->and($stored->status)->toBe('pending')->and(bcaLocalState($booking->guid)['booking'])->toBe('awaiting_payment');
    $overrides = $padded ? [] : ['virtualAccountNo' => '1900890000000001', 'partnerServiceId' => BCA_LOCAL_PARTNER];

    bcaLocalNotify($attempt['attempt_guid'], $overrides)->assertOk()->assertJsonPath('responseCode', '2002500')
        ->assertJsonPath('virtualAccountData.trxId', $stored->merchant_reference);

    $state = bcaLocalState($booking->guid);
    expect($state['booking'])->toBe('confirmed')->and($state['intent'])->toBe('paid')->and($state['attempts'])->toBe(['paid'])
        ->and($state['events'])->toBe(['capture' => 'applied'])->and($state['confirmed_history'])->toBe(1)
        ->and($state['qr'])->toBe(2)->and($state['ticket_events'])->toBe(1)->and($state['receipts'])->toBe(1);
    $paid = DB::table('payment_attempts')->where('guid', $attempt['attempt_guid'])->first();
    expect($paid->provider_reference)->toBe('1900890000000001')->and($paid->verified_paid_at)->not->toBeNull();
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/payment-intent', bearer($owner))->assertOk()
        ->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.last_attempt.channel', 'bca');
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/tickets', bearer($owner))->assertOk()->assertJsonPath('data.tickets.0.qr_available', true);
})->with(['padded VA as in the official examples' => [true], 'unpadded VA' => [false]]);

it('accepts a pretty-printed body only when the signature covers the minified JSON of that same body', function () {
    [$owner, $booking] = bcaLocalBooking();
    bcaLocalFakeCreate();
    $attempt = bcaLocalStart($owner, $booking);

    $foreign = bcaLocalNotify($attempt['attempt_guid'], [], [], ['pretty' => true, 'signed_body' => json_encode(['x' => 1])]);
    expect($foreign->status())->toBe(401)->and(bcaLocalState($booking->guid)['booking'])->toBe('awaiting_payment');

    bcaLocalNotify($attempt['attempt_guid'], [], [], ['pretty' => true])->assertOk()->assertJsonPath('responseCode', '2002500');
    expect(bcaLocalState($booking->guid)['booking'])->toBe('confirmed');
});

it('acknowledges duplicates and retries without a second settlement, history, ticket or receipt', function () {
    [$owner, $booking] = bcaLocalBooking();
    bcaLocalFakeCreate();
    $attempt = bcaLocalStart($owner, $booking);

    bcaLocalNotify($attempt['attempt_guid'])->assertOk()->assertJsonPath('responseCode', '2002500');
    $first = bcaLocalState($booking->guid);
    bcaLocalNotify($attempt['attempt_guid'])->assertOk()->assertJsonPath('responseCode', '2002500');
    bcaLocalNotify($attempt['attempt_guid'], ['paymentRequestId' => '20261110000000000002'])->assertOk();

    $state = bcaLocalState($booking->guid);
    expect($first['booking'])->toBe('confirmed')->and($state['booking'])->toBe('confirmed')->and($state['tickets'])->toBe($first['tickets'])
        ->and($state['confirmed_history'])->toBe(1)->and($state['ticket_events'])->toBe(1)->and($state['receipts'])->toBe(1)->and($state['qr'])->toBe(2)
        ->and(DB::table('payment_events')->where('disposition', 'applied')->count())->toBe(1)
        ->and(DB::table('payment_events')->where('disposition', 'duplicate_capture')->count())->toBe(1)
        ->and($state['event_count'])->toBe(2);
    $this->artisan('tickets:issue-qr')->assertSuccessful();
    expect(bcaLocalState($booking->guid)['tickets'])->toBe($first['tickets']);
});

it('rejects an invalid signature, credentials or headers before touching any payment record', function (array $overrides, array $headers, array $sign) {
    [$owner, $booking] = bcaLocalBooking();
    bcaLocalFakeCreate();
    $attempt = bcaLocalStart($owner, $booking);
    $before = bcaLocalState($booking->guid);

    bcaLocalNotify($attempt['attempt_guid'], $overrides, $headers, $sign)->assertUnauthorized();

    expect(bcaLocalState($booking->guid))->toBe($before)->and($before['event_count'])->toBe(0)->and($before['booking'])->toBe('awaiting_payment');
})->with([
    'forged signature' => [[], ['HTTP_X_SIGNATURE' => 'Zm9yZ2Vk'], []],
    'signed with another secret' => [[], [], ['secret' => 'another-secret']],
    'signed with another token than the bearer' => [[], [], ['token' => 'another-token']],
    'signature over a different body' => [[], [], ['signed_body' => '{"paidAmount":{"value":"1.00","currency":"IDR"}}']],
    'timestamp outside the 5 minute window' => [[], [], ['timestamp' => '2026-11-10T08:30:00+00:00']],
    'wrong partner id' => [[], ['HTTP_X_PARTNER_ID' => 'MCH-OTHER'], []],
    'missing bearer token' => [[], ['HTTP_AUTHORIZATION' => ''], []],
    'non numeric external id' => [[], ['HTTP_X_EXTERNAL_ID' => 'abc'], []],
    'unconfigured notification CHANNEL-ID' => [[], ['HTTP_CHANNEL_ID' => 'H2H'], []],
]);

it('refuses evidence that does not match the BCA VA, bank or amount', function (array $overrides, int $status) {
    [$owner, $booking] = bcaLocalBooking();
    bcaLocalFakeCreate();
    $attempt = bcaLocalStart($owner, $booking);

    $response = bcaLocalNotify($attempt['attempt_guid'], $overrides);

    $state = bcaLocalState($booking->guid);
    expect($response->status())->toBe($status)->and($state['booking'])->not->toBe('confirmed')->and($state['qr'])->toBe(0)->and($state['receipts'])->toBe(0);
    if ($status === 422) {
        expect($state['event_count'])->toBe(0)->and($state['intent'])->toBe('processing');
    }
})->with([
    'VA that is not partner + customerNo' => [['virtualAccountNo' => '   1900890000000009'], 422],
    'customerNo without the BCA prefix' => [['customerNo' => '10000000001', 'virtualAccountNo' => '   1900810000000001'], 422],
    'another partnerServiceId' => [['partnerServiceId' => '   19009', 'virtualAccountNo' => '   1900990000000001'], 422],
    'declared channel of another bank' => [['additionalInfo' => ['channel' => 'VIRTUAL_ACCOUNT_BRI']], 401],
    'declared channel missing' => [['_remove' => ['additionalInfo']], 422],
    'debit transaction type' => [['virtualAccountTrxType' => 'D'], 422],
    'amount with decimals' => [['paidAmount' => ['value' => '10000.50']], 422],
    'non IDR currency' => [['paidAmount' => ['currency' => 'USD']], 422],
]);

it('quarantines a well formed notification whose amount or VA differs from the stored attempt', function (array $overrides) {
    [$owner, $booking] = bcaLocalBooking();
    bcaLocalFakeCreate();
    $attempt = bcaLocalStart($owner, $booking);

    bcaLocalNotify($attempt['attempt_guid'], $overrides)->assertOk();

    $state = bcaLocalState($booking->guid);
    expect($state['booking'])->not->toBe('confirmed')->and($state['intent'])->toBe('needs_review')->and($state['events'])->toBe(['capture' => 'quarantined'])
        ->and($state['qr'])->toBe(0)->and($state['receipts'])->toBe(0)->and($state['confirmed_history'])->toBe(0);
})->with([
    'underpaid' => [['paidAmount' => ['value' => '1000.00']]],
    'overpaid' => [['paidAmount' => ['value' => '999000.00']]],
    'valid BCA VA that is not the one issued' => [['customerNo' => '90000000002', 'virtualAccountNo' => '   1900890000000002']],
]);

it('rejects an unknown trxId and keeps a BCA notification away from a BNI attempt', function () {
    $client = Mockery::mock(DokuClient::class)->makePartial();
    $client->shouldReceive('expiryWindowReason')->andReturn(null);
    app()->instance(DokuClient::class, $client);
    [$owner, $booking] = bcaLocalBooking();
    Http::fake([
        'https://api-sandbox.doku.com/authorization/v1/access-token/b2b' => Http::response(['responseCode' => '2007300', 'accessToken' => 'create-token', 'tokenType' => 'Bearer', 'expiresIn' => 900]),
        'https://api-sandbox.doku.com/virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va' => fn (ClientRequest $request) => Http::response(['responseCode' => '2002700',
            'virtualAccountData' => array_replace($request->data(), ['customerNo' => '30000000000000000001', 'virtualAccountNo' => '9882917230000000000000000001'])]),
    ]);
    $bni = test()->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts', ['method_code' => 'virtual_account', 'channel' => 'bni'],
        bearer($owner) + ['Idempotency-Key' => 'bni-local'])->assertOk()->json('data');
    $reference = DB::table('payment_attempts')->where('guid', $bni['attempt_guid'])->value('merchant_reference');

    $crossBank = bcaLocalNotify($bni['attempt_guid'], ['trxId' => $reference]);
    expect($crossBank->status())->toBeGreaterThanOrEqual(400)->and(bcaLocalState($booking->guid)['booking'])->not->toBe('confirmed');
    $unknown = bcaLocalNotify($bni['attempt_guid'], ['trxId' => 'UNKNOWN-TRX-REFERENCE']);
    expect($unknown->status())->toBeGreaterThanOrEqual(400)->toBeLessThan(500);
    expect(bcaLocalState($booking->guid))->toMatchArray(['booking' => 'awaiting_payment', 'event_count' => 0, 'qr' => 0]);

    // BNI itself is untouched: its own CHANNEL-ID and channel still settle it while BCA is enabled.
    $payload = ['partnerServiceId' => '98829172', 'customerNo' => '30000000000000000001', 'virtualAccountNo' => '9882917230000000000000000001',
        'trxId' => $reference, 'paymentRequestId' => '1234567890', 'paidAmount' => ['value' => '20000.00', 'currency' => 'IDR'],
        'virtualAccountTrxType' => 'C', 'trxDateTime' => now()->toIso8601String(), 'additionalInfo' => ['channel' => 'VIRTUAL_ACCOUNT_BNI']];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $time = now()->toIso8601String();
    $signature = base64_encode(hash_hmac('sha512', 'POST:/api/v1/webhooks/doku/va:'.BCA_LOCAL_TOKEN.':'.hash('sha256', $body).':'.$time, BCA_LOCAL_SECRET, true));
    $this->call('POST', '/api/v1/webhooks/doku/va', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PARTNER_ID' => 'MCH-TEST', 'HTTP_CHANNEL_ID' => 'VA004',
        'HTTP_X_EXTERNAL_ID' => '202611100000009', 'HTTP_AUTHORIZATION' => 'Bearer '.BCA_LOCAL_TOKEN, 'HTTP_X_TIMESTAMP' => $time, 'HTTP_X_SIGNATURE' => $signature], $body)
        ->assertOk()->assertJsonPath('responseCode', '2002500');
    expect(bcaLocalState($booking->guid))->toMatchArray(['booking' => 'confirmed', 'qr' => 2]);
});

it('does not confirm or issue tickets for a payment dated after the payment deadline', function (bool $runExpiry) {
    [$owner, $booking] = bcaLocalBooking();
    bcaLocalFakeCreate();
    $attempt = bcaLocalStart($owner, $booking);
    $this->travelTo(CarbonImmutable::parse(DB::table('bookings')->where('guid', $booking->guid)->value('payment_expires_at'), 'UTC')->addMinute());
    if ($runExpiry) {
        app(BookingService::class)->expireDue();
    }

    bcaLocalNotify($attempt['attempt_guid'])->assertOk()->assertJsonPath('responseCode', '2002500');

    $state = bcaLocalState($booking->guid);
    expect($state['booking'])->not->toBe('confirmed')->and($state['confirmed_history'])->toBe(0)->and($state['qr'])->toBe(0)
        ->and($state['ticket_events'])->toBe(0)->and($state['receipts'])->toBe(0)->and($state['events'])->toBe(['capture' => 'unapplied'])
        ->and($state['intent'])->toBe('needs_review');
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/tickets', bearer($owner))->assertOk()->assertJsonPath('data.tickets.0.qr_available', false);
})->with(['booking still awaiting payment' => [false], 'booking already moved by expiry' => [true]]);

it('keeps BCA closed on the normal runtime channel list even for a correctly signed notification', function () {
    [$owner, $booking] = bcaLocalBooking();
    bcaLocalFakeCreate();
    $attempt = bcaLocalStart($owner, $booking);
    // Same value as the normal runtime (.env): BCA configured to appear, but not in DOKU_VERIFIED_CHANNELS.
    config(['doku.verified_channels' => ['ewallet_dana']]);
    $before = bcaLocalState($booking->guid);

    bcaLocalNotify($attempt['attempt_guid'])->assertUnauthorized();

    expect(bcaLocalState($booking->guid))->toBe($before)->and($before['booking'])->toBe('awaiting_payment');
    $other = bcaLocalBooking();
    $this->postJson('/api/v1/bookings/'.$other[1]->guid.'/payment-attempts', ['method_code' => 'virtual_account', 'channel' => 'bca'],
        bearer($other[0]) + ['Idempotency-Key' => 'closed-gate'])->assertStatus(409)->assertJsonPath('code', 'channel_not_verified');
});
