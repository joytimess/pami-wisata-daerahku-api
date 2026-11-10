<?php

use App\Services\BookingService;
use App\Services\Doku\DokuChannelRegistry;
use App\Services\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Bank values below are fixtures for this test only. They are not DOKU-assigned BINs or CHANNEL-ID values.
 */
const VA_BANK_FIXTURES = [
    'bca' => ['code' => 'va_bca', 'doku' => 'VIRTUAL_ACCOUNT_BCA', 'partner' => '19008', 'prefix' => '5', 'notify' => 'VAT01'],
    'bri' => ['code' => 'va_bri', 'doku' => 'VIRTUAL_ACCOUNT_BRI', 'partner' => '77712', 'prefix' => '7', 'notify' => 'VAT02'],
    'mandiri' => ['code' => 'va_mandiri', 'doku' => 'VIRTUAL_ACCOUNT_BANK_MANDIRI', 'partner' => '88801', 'prefix' => '8', 'notify' => 'VAT03'],
];

beforeEach(function () {
    // Legacy adapters stay shipped for recovery; this suite lifts only the new-issuance release scope to exercise them.
    app()->instance(DokuChannelRegistry::class, new class extends DokuChannelRegistry
    {
        protected function issuanceScope(): array
        {
            return array_keys($this->all());
        }
    });
    $this->travelTo(CarbonImmutable::parse('2026-11-08 08:00:00', 'UTC'));
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $this->vaKey = tempnam(sys_get_temp_dir(), 'doku-va-');
    file_put_contents($this->vaKey, $pem);
    $banks = [];
    foreach (VA_BANK_FIXTURES as $bank => $fixture) {
        $banks[$bank] = ['partner_service_id' => $fixture['partner'], 'customer_prefix' => $fixture['prefix'], 'notification_channel_id' => $fixture['notify']];
    }
    config(['operations.demo_payments_enabled' => false, 'app.debug' => false, 'doku.enabled' => true, 'doku.environment' => 'sandbox',
        'doku.client_id' => 'MCH-TEST', 'doku.secret_key' => 'test-only-secret', 'doku.merchant_private_key_path' => $this->vaKey,
        'doku.merchant_private_key_passphrase' => '', 'doku.partner_service_id' => '98829172', 'doku.customer_prefix' => '3',
        'doku.channels' => ['va_bni', 'va_bca', 'va_bri', 'va_mandiri'], 'doku.verified_channels' => ['va_bni', 'va_bca', 'va_bri', 'va_mandiri'],
        'doku.va_banks' => $banks, 'doku.webhook_url' => 'https://sandbox.example.com/api/v1/webhooks/doku/va']);
    Http::preventStrayRequests();
});

afterEach(function () {
    unlink($this->vaKey);
});

function vaBooking(): array
{
    [$destination, $date] = seedDestination(capacity: 5, price: 10000, days: 5);
    $owner = makeUser();
    $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date, 2) + ['idempotency_key' => (string) Str::uuid()]);
    $booking = app(BookingService::class)->decide(makeUser(2), $booking->guid, true);

    return [$owner, app(TicketService::class)->booking($booking->guid)];
}

function vaFakeProvider(string $bank, bool $paddedVa = false): void
{
    $f = VA_BANK_FIXTURES[$bank];
    Http::fake([
        'https://api-sandbox.doku.com/authorization/v1/access-token/b2b' => Http::response(['responseCode' => '2007300', 'accessToken' => 'test-token', 'tokenType' => 'Bearer', 'expiresIn' => 900]),
        'https://api-sandbox.doku.com/virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va' => function (ClientRequest $request) use ($f, $paddedVa) {
            $customer = $f['prefix'].str_pad('1', 20 - strlen($f['prefix']), '0', STR_PAD_LEFT);

            return Http::response(['responseCode' => '2002700', 'responseMessage' => 'Successful', 'virtualAccountData' => [
                'partnerServiceId' => str_pad($f['partner'], 8, ' ', STR_PAD_LEFT), 'customerNo' => $customer,
                'virtualAccountNo' => ($paddedVa ? str_pad($f['partner'], 8, ' ', STR_PAD_LEFT) : $f['partner']).$customer,
                'trxId' => $request['trxId'], 'virtualAccountTrxType' => 'C', 'totalAmount' => $request['totalAmount'],
                'expiredDate' => $request['expiredDate'], 'additionalInfo' => ['channel' => $f['doku']],
            ]]);
        },
    ]);
}

function vaStart(object $owner, object $booking, string $bank, string $key = 'va-start'): array
{
    return test()->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts', ['method_code' => 'virtual_account', 'channel' => $bank],
        bearer($owner) + ['Idempotency-Key' => $key])->assertOk()->json('data');
}

function vaWebhook(string $bank, string $attemptGuid, array $overrides = [], array $headers = [])
{
    $f = VA_BANK_FIXTURES[$bank];
    $attempt = DB::table('payment_attempts')->where('guid', $attemptGuid)->first();
    $va = $attempt->provider_reference;
    $payload = array_replace_recursive(['partnerServiceId' => str_pad($f['partner'], 8, ' ', STR_PAD_LEFT), 'customerNo' => substr($va, strlen($f['partner'])),
        'virtualAccountNo' => $va, 'virtualAccountName' => 'Test Visitor', 'trxId' => $attempt->merchant_reference, 'paymentRequestId' => '987654321',
        'paidAmount' => ['value' => $attempt->amount.'.00', 'currency' => 'IDR'], 'virtualAccountTrxType' => 'C',
        'trxDateTime' => now()->toIso8601String(), 'additionalInfo' => ['channel' => $f['doku']]], $overrides);
    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $time = now()->toIso8601String();
    $signature = base64_encode(hash_hmac('sha512', 'POST:/api/v1/webhooks/doku/va:test-token:'.hash('sha256', $body).':'.$time, 'test-only-secret', true));
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PARTNER_ID' => 'MCH-TEST', 'HTTP_CHANNEL_ID' => $f['notify'], 'HTTP_X_EXTERNAL_ID' => '202611080000001',
        'HTTP_AUTHORIZATION' => 'Bearer test-token', 'HTTP_X_TIMESTAMP' => $time, 'HTTP_X_SIGNATURE' => $signature];

    return test()->call('POST', '/api/v1/webhooks/doku/va', [], [], [], array_replace($server, $headers), $body);
}

it('creates a fixed-bill DGPC VA with the bank-specific channel and merchant values', function (string $bank) {
    [$owner, $booking] = vaBooking();
    vaFakeProvider($bank);
    $f = VA_BANK_FIXTURES[$bank];

    $data = vaStart($owner, $booking, $bank);

    expect($data)->toMatchArray(['status' => 'pending', 'method_code' => 'virtual_account', 'channel' => $bank, 'amount' => 20000]);
    expect($data['instruction'])->toMatchArray(['presentation_type' => 'virtual_account', 'bank_code' => $bank])
        ->and($data['instruction']['va_number'])->toStartWith($f['partner'])->and($data['instruction'])->not->toHaveKey('_provider');
    Http::assertSent(fn (ClientRequest $request) => ! str_ends_with($request->url(), '/create-va') || (
        $request['additionalInfo']['channel'] === $f['doku'] && $request['additionalInfo']['virtualAccountConfig']['reusableStatus'] === false
        && $request['virtualAccountTrxType'] === 'C' && $request['totalAmount']['value'] === '20000.00'
        && $request['partnerServiceId'] === str_pad($f['partner'], 8, ' ', STR_PAD_LEFT) && $request['customerNo'] === $f['prefix']
        && $request['virtualAccountNo'] === $request['partnerServiceId'].$request['customerNo']));
    Http::assertSentCount(2);
})->with(['bca', 'bri', 'mandiri']);

it('settles once when create and notification pad the VA differently', function (string $bank, bool $paddedCreate, bool $paddedNotification) {
    [$owner, $booking] = vaBooking();
    vaFakeProvider($bank, $paddedCreate);
    $attempt = vaStart($owner, $booking, $bank);
    $stored = DB::table('payment_attempts')->where('guid', $attempt['attempt_guid'])->value('provider_reference');

    expect($stored)->toMatch('/^\d+$/D')->and($attempt['instruction']['va_number'])->toBe($stored);
    $overrides = $paddedNotification ? ['virtualAccountNo' => str_pad(VA_BANK_FIXTURES[$bank]['partner'], 8, ' ', STR_PAD_LEFT).substr($stored, strlen(VA_BANK_FIXTURES[$bank]['partner']))] : [];

    vaWebhook($bank, $attempt['attempt_guid'], $overrides)->assertOk()->assertJsonPath('responseCode', '2002500');

    expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBe('confirmed')
        ->and(DB::table('payment_events')->where('event_type', 'capture')->where('disposition', 'applied')->count())->toBe(1);
})->with([
    'bca padded create, padded notification' => ['bca', true, true],
    'bca padded create, plain notification' => ['bca', true, false],
    'bca plain create, padded notification' => ['bca', false, true],
    'bri padded create, padded notification' => ['bri', true, true],
]);

it('still rejects a notification whose VA does not match its partner and customer numbers', function () {
    [$owner, $booking] = vaBooking();
    vaFakeProvider('bca', true);
    $attempt = vaStart($owner, $booking, 'bca');
    $stored = DB::table('payment_attempts')->where('guid', $attempt['attempt_guid'])->value('provider_reference');

    $response = vaWebhook('bca', $attempt['attempt_guid'], ['virtualAccountNo' => '   '.substr($stored, 0, -1).'9']);

    expect($response->status())->toBe(422);
    expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->not->toBe('confirmed')
        ->and(DB::table('payment_events')->count())->toBe(0);
});

it('confirms the booking once from a verified paid notification and ignores the duplicate', function (string $bank) {
    [$owner, $booking] = vaBooking();
    vaFakeProvider($bank);
    $attempt = vaStart($owner, $booking, $bank);

    vaWebhook($bank, $attempt['attempt_guid'])->assertOk()->assertJsonPath('responseCode', '2002500');
    vaWebhook($bank, $attempt['attempt_guid'])->assertOk()->assertJsonPath('responseCode', '2002500');

    expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBe('confirmed')
        ->and(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'confirmed')->count())->toBe(1)
        ->and(DB::table('payment_events')->where('event_type', 'capture')->where('disposition', 'applied')->count())->toBe(1);
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/payment-intent', bearer($owner))->assertOk()
        ->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.last_attempt.channel', $bank);
})->with(['bca', 'bri', 'mandiri']);

it('rejects notifications with the wrong CHANNEL-ID, a tampered signature or a tampered amount', function (string $bank) {
    [$owner, $booking] = vaBooking();
    vaFakeProvider($bank);
    $attempt = vaStart($owner, $booking, $bank);

    vaWebhook($bank, $attempt['attempt_guid'], [], ['HTTP_CHANNEL_ID' => 'VA004'])->assertUnauthorized();
    vaWebhook($bank, $attempt['attempt_guid'], [], ['HTTP_X_SIGNATURE' => base64_encode('forged')])->assertUnauthorized();

    $review = vaWebhook($bank, $attempt['attempt_guid'], ['paidAmount' => ['value' => '1000.00']]);
    expect($review->status())->toBe(200);
    expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->not->toBe('confirmed');
    expect(DB::table('payment_intents')->value('status'))->toBe('needs_review');
})->with(['bca', 'bri', 'mandiri']);

it('keeps a bank unable to settle until its notification CHANNEL-ID is configured', function (string $bank) {
    config(['doku.va_banks.'.$bank.'.notification_channel_id' => '']);
    [$owner, $booking] = vaBooking();

    expect(app(DokuChannelRegistry::class)->reason(VA_BANK_FIXTURES[$bank]['code']))->toBe('channel_contract_unverified');
    $this->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts', ['method_code' => 'virtual_account', 'channel' => $bank],
        bearer($owner) + ['Idempotency-Key' => 'no-notify'])->assertStatus(409)->assertJsonPath('code', 'channel_contract_unverified');
    $this->assertDatabaseCount('payment_attempts', 0);
    Http::assertNothingSent();
})->with(['bca', 'bri', 'mandiri']);

it('disables only the bank whose merchant parameters are invalid', function (string $bank) {
    config(['doku.va_banks.'.$bank.'.partner_service_id' => 'abc']);
    $reasons = app(DokuChannelRegistry::class)->reasons();

    expect($reasons[VA_BANK_FIXTURES[$bank]['code']])->toBe('doku_not_configured');
    foreach (array_diff(['va_bni', 'va_bca', 'va_bri', 'va_mandiri'], [VA_BANK_FIXTURES[$bank]['code']]) as $other) {
        expect($reasons[$other])->toBeNull();
    }
})->with(['bca', 'bri', 'mandiri']);

it('never applies a payment declared for another bank to the pending attempt', function () {
    [$owner, $booking] = vaBooking();
    vaFakeProvider('bca');
    $attempt = vaStart($owner, $booking, 'bca');
    $merchantReference = DB::table('payment_attempts')->where('guid', $attempt['attempt_guid'])->value('merchant_reference');
    $bri = VA_BANK_FIXTURES['bri'];
    $customer = $bri['prefix'].str_pad('1', 20 - strlen($bri['prefix']), '0', STR_PAD_LEFT);

    $response = vaWebhook('bri', $attempt['attempt_guid'], ['trxId' => $merchantReference, 'virtualAccountNo' => $bri['partner'].$customer, 'customerNo' => $customer]);

    expect($response->status())->toBeGreaterThanOrEqual(400);
    expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->not->toBe('confirmed')
        ->and(DB::table('payment_events')->count())->toBe(0);
});

it('does not confirm a notification dated after the payment deadline', function () {
    [$owner, $booking] = vaBooking();
    vaFakeProvider('bri');
    $attempt = vaStart($owner, $booking, 'bri');
    $this->travelTo(CarbonImmutable::parse(DB::table('bookings')->where('guid', $booking->guid)->value('payment_expires_at'), 'UTC')->addMinute());

    vaWebhook('bri', $attempt['attempt_guid'])->assertOk();

    expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->not->toBe('confirmed');
});
