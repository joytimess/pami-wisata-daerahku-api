<?php

use App\Services\BookingService;
use App\Services\Doku\DokuChannelRegistry;
use App\Services\Doku\DokuClient;
use App\Services\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Fixture tests/Fixtures/doku/bca-status-success.json is the real BCA Sandbox check-status answer of 2026-11-10
 * (simulator "Payment Success") with the VA, customer number, request ids and trxId replaced by synthetic values.
 * It documents what DOKU returns today; it does not change the settlement policy.
 */
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
    $this->bcaKey = tempnam(sys_get_temp_dir(), 'doku-bca-');
    file_put_contents($this->bcaKey, $pem);
    config(['operations.demo_payments_enabled' => false, 'app.debug' => false, 'doku.enabled' => true, 'doku.environment' => 'sandbox',
        'doku.client_id' => 'MCH-TEST', 'doku.secret_key' => 'test-only-secret', 'doku.merchant_private_key_path' => $this->bcaKey,
        'doku.merchant_private_key_passphrase' => '', 'doku.partner_service_id' => '98829172', 'doku.customer_prefix' => '3',
        'doku.channels' => ['va_bni', 'va_bca'], 'doku.verified_channels' => ['va_bni', 'va_bca'],
        'doku.va_banks.bca' => ['partner_service_id' => '19008', 'customer_prefix' => '9', 'notification_channel_id' => 'H2H'],
        'doku.webhook_url' => 'https://sandbox.example.com/api/v1/webhooks/doku/va']);
    Http::preventStrayRequests();
});

afterEach(function () {
    unlink($this->bcaKey);
});

/** @return array<string, mixed> */
function bcaStatusFixture(string $trxId = 'FIXTURE-TRX-REF'): array
{
    $answer = json_decode(file_get_contents(base_path('tests/Fixtures/doku/bca-status-success.json')), true, 16, JSON_THROW_ON_ERROR);
    $answer['additionalInfo']['trxId'] = $trxId;

    return $answer;
}

function bcaStatusTokenFake(): array
{
    return ['https://api-sandbox.doku.com/authorization/v1/access-token/b2b' => Http::response(['responseCode' => '2007300', 'accessToken' => 'test-token', 'tokenType' => 'Bearer', 'expiresIn' => 900])];
}

function bcaStatusAttempt(string $channel = 'bca', array $provider = ['partnerServiceId' => '   19008', 'customerNo' => '90000000001', 'virtualAccountNo' => '   1900890000000001']): object
{
    return (object) ['guid' => (string) Str::uuid(), 'provider' => 'doku', 'environment' => 'sandbox', 'method' => 'virtual_account', 'channel' => $channel,
        'merchant_reference' => 'FIXTURE-TRX-REF', 'instruction_ciphertext' => Crypt::encryptString(json_encode(['_provider' => $provider]))];
}

/** @return array{0: object, 1: object, 2: array<string, mixed>} */
function bcaStatusStartAttempt(): array
{
    [$destination, $date] = seedDestination(capacity: 5, price: 10000, days: 5);
    $owner = makeUser();
    $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date, 1) + ['idempotency_key' => (string) Str::uuid()]);
    $booking = app(TicketService::class)->booking(app(BookingService::class)->decide(makeUser(2), $booking->guid, true)->guid);
    Http::fake(bcaStatusTokenFake() + ['https://api-sandbox.doku.com/virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va' => fn (ClientRequest $request) => Http::response([
        'responseCode' => '2002700', 'responseMessage' => 'Successful', 'virtualAccountData' => [
            'partnerServiceId' => '   19008', 'customerNo' => '90000000001', 'virtualAccountNo' => '   1900890000000001', 'trxId' => $request['trxId'],
            'totalAmount' => $request['totalAmount'], 'virtualAccountTrxType' => 'C', 'expiredDate' => $request['expiredDate'],
            'additionalInfo' => ['howToPayApi' => 'https://sandbox.example.com/a', 'howToPayPage' => 'https://sandbox.example.com/b']]])]);
    $attempt = test()->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts', ['method_code' => 'virtual_account', 'channel' => 'bca'],
        bearer($owner) + ['Idempotency-Key' => 'bca-status-start'])->assertOk()->json('data');

    return [$owner, $booking, $attempt];
}

it('keeps the real check-status answer within the documented SNAP contract', function () {
    $answer = bcaStatusFixture();
    $data = $answer['virtualAccountData'];

    expect($answer['responseCode'])->toBe('2002600')->and($answer['responseMessage'])->toBe('Successful')
        ->and($data['paymentFlagReason'])->toBe(['english' => 'Success', 'indonesia' => 'Sukses'])
        ->and(app(DokuClient::class)->parseAmount($data['paidAmount']))->toBe(10000)
        ->and($data['partnerServiceId'])->toBe('   19008')->and(ltrim($data['virtualAccountNo'], ' '))->toBe(trim($data['partnerServiceId']).$data['customerNo'])
        ->and($data['paymentRequestId'])->toBe($data['inquiryRequestId'])->and($answer['additionalInfo']['acquirer']['id'])->toBe('BCA');
});

it('carries no payment time anywhere in the real answer', function () {
    $flat = json_encode(bcaStatusFixture());

    foreach (['trxDateTime', 'paidTime', 'paymentDate', 'paidAt', 'transactionDate', 'expiredDate'] as $timeKey) {
        expect($flat)->not->toContain($timeKey);
    }
    expect($flat)->not->toMatch('/\d{4}-\d{2}-\d{2}T/');
});

it('queries BCA status with the stored raw VA only and sends no invented request ids', function () {
    Http::fake(bcaStatusTokenFake() + ['https://api-sandbox.doku.com/orders/v1.0/transfer-va/status' => Http::response(bcaStatusFixture())]);

    $result = app(DokuClient::class)->status(bcaStatusAttempt());

    expect($result)->toBe(['status' => 'unknown', 'reason' => 'provider_terminal_contract_unverified']);
    Http::assertSent(function (ClientRequest $request) {
        if (! str_ends_with($request->url(), '/transfer-va/status')) {
            return false;
        }

        return array_keys($request->data()) === ['partnerServiceId', 'customerNo', 'virtualAccountNo', 'additionalInfo']
            && $request['virtualAccountNo'] === '   1900890000000001' && ! isset($request['inquiryRequestId']) && ! isset($request['paymentRequestId']);
    });
    Http::assertSentCount(2);
});

it('does not settle a BCA booking from a Success status without a payment time', function () {
    [$owner, $booking, $attempt] = bcaStatusStartAttempt();
    $reference = DB::table('payment_attempts')->where('guid', $attempt['attempt_guid'])->value('merchant_reference');
    Http::fake(bcaStatusTokenFake() + ['https://api-sandbox.doku.com/orders/v1.0/transfer-va/status' => Http::response(bcaStatusFixture($reference))]);

    $this->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts/'.$attempt['attempt_guid'].'/refresh', [], bearer($owner));

    expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->not->toBe('confirmed')
        ->and(DB::table('payment_events')->count())->toBe(0)
        ->and(DB::table('payment_attempts')->where('guid', $attempt['attempt_guid'])->value('status'))->toBeIn(['pending', 'unknown'])
        ->and(DB::table('payment_intents')->where('status', 'paid')->count())->toBe(0);
    expect($this->getJson('/api/v1/bookings/'.$booking->guid.'/payment-intent', bearer($owner))->assertOk()->json('data.payment_status'))->toBeIn(['unpaid', 'unverified']);
});

it('rejects BCA status answers that do not belong to the stored transaction', function (callable $mutate, string $reason) {
    $answer = $mutate(bcaStatusFixture());
    Http::fake(bcaStatusTokenFake() + ['https://api-sandbox.doku.com/orders/v1.0/transfer-va/status' => Http::response($answer)]);

    expect(app(DokuClient::class)->status(bcaStatusAttempt()))->toBe(['status' => 'unknown', 'reason' => $reason]);
})->with([
    'another trxId' => [fn (array $a) => array_replace_recursive($a, ['additionalInfo' => ['trxId' => 'someone-elses']]), 'provider_response_mismatch'],
    'another VA' => [fn (array $a) => array_replace_recursive($a, ['virtualAccountData' => ['virtualAccountNo' => '   1900890000000002']]), 'provider_response_mismatch'],
    'another customer number' => [fn (array $a) => array_replace_recursive($a, ['virtualAccountData' => ['customerNo' => '90000000002']]), 'provider_response_mismatch'],
    'another BIN' => [fn (array $a) => array_replace_recursive($a, ['virtualAccountData' => ['partnerServiceId' => '   19009']]), 'provider_response_mismatch'],
    'another acquirer' => [fn (array $a) => array_replace_recursive($a, ['additionalInfo' => ['acquirer' => ['id' => 'BNI']]]), 'provider_response_mismatch'],
    'missing acquirer' => [function (array $a) {
        unset($a['additionalInfo']['acquirer']);

        return $a;
    }, 'provider_response_mismatch'],
    'missing data' => [function (array $a) {
        unset($a['virtualAccountData']);

        return $a;
    }, 'provider_response_mismatch'],
    'data as a list' => [fn (array $a) => ['virtualAccountData' => [$a['virtualAccountData']]] + $a, 'provider_response_mismatch'],
    'data as a string' => [fn (array $a) => ['virtualAccountData' => 'Success'] + $a, 'provider_response_mismatch'],
    'not the success code' => [fn (array $a) => ['responseCode' => '4042601'] + $a, 'provider_status_unresolved'],
    'no response code' => [function (array $a) {
        unset($a['responseCode']);

        return $a;
    }, 'provider_status_unresolved'],
]);

it('treats a malformed or non-object status body as unavailable', function (mixed $body) {
    Http::fake(bcaStatusTokenFake() + ['https://api-sandbox.doku.com/orders/v1.0/transfer-va/status' => Http::response($body, 200)]);

    expect(app(DokuClient::class)->status(bcaStatusAttempt()))->toBe(['status' => 'unknown', 'reason' => 'provider_unavailable']);
})->with(['plain text' => ['not json'], 'json scalar' => ['"Success"'], 'empty body' => ['']]);

it('never lets a BCA-shaped answer resolve a BNI attempt', function () {
    $bni = ['partnerServiceId' => '98829172', 'customerNo' => '30000000000000000001', 'virtualAccountNo' => '9882917230000000000000000001'];
    $answer = bcaStatusFixture();
    $answer['virtualAccountData'] = $bni + $answer['virtualAccountData'];
    Http::fake(bcaStatusTokenFake() + ['https://api-sandbox.doku.com/orders/v1.0/transfer-va/status' => Http::response($answer)]);

    expect(app(DokuClient::class)->status(bcaStatusAttempt('bni', $bni)))->toBe(['status' => 'unknown', 'reason' => 'provider_response_mismatch']);
});

it('keeps the BNI pending status contract unchanged', function () {
    $bni = ['partnerServiceId' => '98829172', 'customerNo' => '30000000000000000001', 'virtualAccountNo' => '9882917230000000000000000001'];
    Http::fake(bcaStatusTokenFake() + ['https://api-sandbox.doku.com/orders/v1.0/transfer-va/status' => Http::response([
        'responseCode' => '2002600', 'virtualAccountData' => $bni + ['paymentFlagReason' => ['english' => 'Pending', 'indonesia' => 'Belum Terbayar']],
        'additionalInfo' => ['trxId' => 'FIXTURE-TRX-REF', 'acquirer' => ['id' => 'BNI']]])]);

    expect(app(DokuClient::class)->status(bcaStatusAttempt('bni', $bni)))->toBe(['status' => 'pending']);
});
