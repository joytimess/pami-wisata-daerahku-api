<?php

use App\Jobs\IssueBookingTicketQr;
use App\Jobs\ProcessMidtransPaymentEvent;
use App\Services\MidtransPaymentService;
use App\Services\PaymentEligibility;
use App\Services\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;

require_once __DIR__.'/Support/MidtransFixtures.php';

uses(RefreshDatabase::class);

/*
 * POST /api/v1/webhooks/midtrans. A notification is a durable trigger, never financial evidence: only the authenticated Status
 * lookup of a locally known order may confirm a payment. Provider interaction is always a fake.
 */
const MTX_WEBHOOK = '/api/v1/webhooks/midtrans';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 08:00:00', 'UTC'));
    mtxConfig();
    Http::preventStrayRequests();
    Queue::fake();
});

it('acknowledges a signed notification only after a durable receipt exists, and trusts nothing in it', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking);
    Http::fake();

    $response = $this->postJson(MTX_WEBHOOK, mtxNotification($attempt))->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $receipt = DB::table('payment_events')->sole();
    expect($receipt->provider)->toBe('midtrans')->and($receipt->environment)->toBe('sandbox')->and($receipt->verification)->toBe('unverified')
        ->and($receipt->event_type)->toBe('status')->and($receipt->disposition)->toBe('received')
        ->and($receipt->payment_attempt_guid)->toBe($attempt->guid)->and($receipt->payment_intent_guid)->toBe($attempt->payment_intent_guid)
        ->and($receipt->amount)->toBeNull()->and($receipt->provider_occurred_at)->toBeNull()
        ->and($receipt->metadata)->not->toContain('81234500001')->not->toContain('signature_key')->not->toContain('settlement');
    expect(json_decode($receipt->metadata, true))->toHaveKeys(['source', 'payload_ciphertext'])
        ->and(json_decode($receipt->metadata, true)['source'])->toBe('midtrans_notification');
    Queue::assertPushed(ProcessMidtransPaymentEvent::class, fn ($job) => $job->eventGuid === $receipt->guid);
    expect(DB::table('bookings')->value('status'))->toBe('awaiting_payment')
        ->and(DB::table('payment_attempts')->where('guid', $attempt->guid)->value('status'))->toBe('pending');
    Http::assertNothingSent();
});

it('rejects a notification with an invalid signature without storing it, looking anything up or paying', function (Closure $tamper) {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking);
    $payload = $tamper(mtxNotification($attempt));
    Http::fake();

    $this->postJson(MTX_WEBHOOK, $payload)->assertUnauthorized();

    $this->assertDatabaseCount('payment_events', 0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
    expect(DB::table('bookings')->value('status'))->toBe('awaiting_payment');
})->with([
    'foreign server key' => [fn (array $p) => ['signature_key' => mtxSignature($p['order_id'], $p['status_code'], $p['gross_amount'], 'SB-Mid-server-ANOTHER')] + $p],
    'tampered amount' => [fn (array $p) => ['gross_amount' => '1.00'] + $p],
    'tampered order id' => [fn (array $p) => ['order_id' => $p['order_id'].'x'] + $p],
    'tampered status code' => [fn (array $p) => ['status_code' => '201'] + $p],
    'signature of another formula' => [fn (array $p) => ['signature_key' => hash('sha512', $p['order_id'].$p['gross_amount'].config('midtrans.server_key'))] + $p],
    'uppercase hex' => [fn (array $p) => ['signature_key' => strtoupper($p['signature_key'])] + $p],
]);

it('rejects malformed notifications without storing or looking anything up', function (Closure $mutate, int $status) {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking);
    Http::fake();
    $payload = $mutate(mtxNotification($attempt));

    $this->call('POST', MTX_WEBHOOK, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], is_string($payload) ? $payload : json_encode($payload))
        ->assertStatus($status);

    $this->assertDatabaseCount('payment_events', 0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([
    'not json' => [fn () => '{"order_id":', 400],
    'json list' => [fn () => '[1,2,3]', 400],
    'missing order id' => [function (array $p) {
        unset($p['order_id']);

        return $p;
    }, 400],
    'missing signature' => [function (array $p) {
        unset($p['signature_key']);

        return $p;
    }, 400],
    'numeric gross amount' => [fn (array $p) => ['gross_amount' => 20000] + $p, 400],
    'unknown transaction status' => [fn (array $p) => ['transaction_status' => 'teleported'] + $p, 400],
    'short signature' => [fn (array $p) => ['signature_key' => 'abc'] + $p, 400],
    'order id with path characters' => [fn (array $p) => ['order_id' => '../../v2/charge'] + $p, 400],
    'oversized order id' => [fn (array $p) => ['order_id' => str_repeat('A', 200)] + $p, 400],
    'oversized body' => [fn (array $p) => $p + ['padding' => str_repeat('x', 20000)], 413],
]);

it('acknowledges a signed notification for an unknown order without any provider lookup', function (Closure $orphan) {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking);
    $orphan($attempt);
    Http::fake();
    $payload = mtxNotification($attempt, 'settlement', ['order_id' => 'PAMI-'.Str::uuid()]);

    $this->postJson(MTX_WEBHOOK, $payload)->assertOk();

    $receipt = DB::table('payment_events')->sole();
    expect($receipt->disposition)->toBe('unknown_order')->and($receipt->payment_attempt_guid)->toBeNull()->and($receipt->payment_intent_guid)->toBeNull();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
    expect(DB::table('bookings')->value('status'))->toBe('awaiting_payment');
})->with([
    'nothing else' => [fn () => null],
    'order of another environment' => [fn (object $attempt) => DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['environment' => 'production'])],
]);

it('treats an order that belongs to another provider as unknown', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, 'pending', 'virtual_account', 'bca', ['provider' => 'doku', 'merchant_reference' => (string) Str::uuid()]);
    DB::table('payment_intents')->where('guid', $attempt->payment_intent_guid)->update(['provider' => 'doku']);
    Http::fake();

    $this->postJson(MTX_WEBHOOK, mtxNotification($attempt))->assertOk();

    expect(DB::table('payment_events')->sole()->disposition)->toBe('unknown_order');
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('does not pay on a forged settlement notification when the authenticated lookup says pending', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking);
    $this->postJson(MTX_WEBHOOK, mtxNotification($attempt, 'settlement'))->assertOk();
    $calls = mtxFakeStatus(mtxStatusBody($attempt, 'pending'));

    mtxDrain();

    expect($calls->calls)->toBe(1)->and($calls->urls[0])->toBe(mtxStatusUrl($attempt));
    expect(DB::table('bookings')->value('status'))->toBe('awaiting_payment')
        ->and(DB::table('payment_attempts')->where('guid', $attempt->guid)->value('status'))->toBe('pending')
        ->and(DB::table('payment_events')->where('event_type', 'capture')->count())->toBe(0)
        ->and(DB::table('payment_events')->where('guid', '!=', '')->where('verification', 'unverified')->value('disposition'))->toBe('processed');
});

it('never pays when the authenticated lookup disagrees with the stored attempt', function (array $override) {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking);
    $this->postJson(MTX_WEBHOOK, mtxNotification($attempt))->assertOk();
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement', $override));

    mtxDrain();

    expect(DB::table('bookings')->value('status'))->toBe('awaiting_payment')
        ->and(DB::table('payment_events')->where('event_type', 'capture')->count())->toBe(0)
        ->and(DB::table('payment_attempts')->where('guid', $attempt->guid)->value('status'))->not->toBe('paid');
})->with([
    'wrong amount' => [['gross_amount' => '1000.00']],
    'wrong currency' => [['currency' => 'USD']],
    'wrong merchant' => [['merchant_id' => 'G999999999']],
    'wrong bank' => [['va_numbers' => [['bank' => 'bni', 'va_number' => '81234500001']]]],
    'wrong order' => [['order_id' => 'PAMI-someone-else']],
    'wrong transaction identity' => [['transaction_id' => 'trx-forged']],
    'challenged fraud status' => [['fraud_status' => 'challenge']],
]);

it('confirms the booking exactly once from a signed notification followed by a verified settlement', function () {
    [$owner, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking);
    $this->postJson(MTX_WEBHOOK, mtxNotification($attempt))->assertOk();
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement'));

    mtxDrain();

    $capture = DB::table('payment_events')->where('event_type', 'capture')->sole();
    expect($capture->verification)->toBe('verified')->and($capture->disposition)->toBe('applied')->and((int) $capture->amount)->toBe(20000)
        ->and($capture->provider)->toBe('midtrans')->and(json_decode($capture->metadata, true)['source'])->toBe('midtrans_status');
    expect(DB::table('bookings')->value('status'))->toBe('confirmed')
        ->and(DB::table('payment_attempts')->where('guid', $attempt->guid)->value('status'))->toBe('paid')
        ->and(DB::table('payment_intents')->value('status'))->toBe('paid')
        ->and(DB::table('payment_intents')->value('applied_attempt_guid'))->toBe($attempt->guid)
        ->and(DB::table('payment_events')->where('verification', 'unverified')->value('disposition'))->toBe('processed')
        ->and(app(PaymentEligibility::class)->status(app(TicketService::class)->booking($booking->guid)))->toBe('paid');
    Queue::assertPushed(IssueBookingTicketQr::class, 1);
    expect(DB::table('booking_status_histories')->where('new_status', 'confirmed')->count())->toBe(1);
});

it('produces no second side effect for duplicate deliveries and replayed processing', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking);
    $payload = mtxNotification($attempt);
    $this->postJson(MTX_WEBHOOK, $payload)->assertOk();
    $this->postJson(MTX_WEBHOOK, $payload)->assertOk();
    $this->postJson(MTX_WEBHOOK, ['transaction_id' => 'unsigned-noise'] + $payload)->assertOk();
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement'));

    expect(DB::table('payment_events')->where('verification', 'unverified')->count())->toBe(1);
    mtxDrain();
    mtxDrain();
    foreach (DB::table('payment_events')->pluck('guid') as $guid) {
        app(MidtransPaymentService::class)->processEvent($guid);
    }

    expect(DB::table('payment_events')->where('event_type', 'capture')->count())->toBe(1)
        ->and(DB::table('booking_status_histories')->where('new_status', 'confirmed')->count())->toBe(1)
        ->and(DB::table('notifications')->where('type', 'booking.payment_receipt')->count())->toBe(1)
        ->and(DB::table('bookings')->value('status'))->toBe('confirmed');
    Queue::assertPushed(IssueBookingTicketQr::class, 1);
});

it('keeps separate receipts for different signed facts', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking);

    $this->postJson(MTX_WEBHOOK, mtxNotification($attempt, 'pending'))->assertOk();
    $this->postJson(MTX_WEBHOOK, mtxNotification($attempt, 'settlement'))->assertOk();
    $this->postJson(MTX_WEBHOOK, mtxNotification($attempt, 'settlement', ['transaction_status' => 'refund']))->assertOk();

    expect(DB::table('payment_events')->where('verification', 'unverified')->count())->toBe(3);
});

it('does not demote a paid attempt when a stale pending notification is processed afterwards', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking);
    $this->postJson(MTX_WEBHOOK, mtxNotification($attempt))->assertOk();
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement'));
    mtxDrain();

    $this->travelTo(now()->addSeconds(10));
    $this->postJson(MTX_WEBHOOK, mtxNotification($attempt, 'pending'))->assertOk();
    $calls = mtxFakeStatus(mtxStatusBody($attempt, 'pending'));
    mtxDrain();

    expect($calls->calls)->toBe(1)->and(DB::table('payment_attempts')->where('guid', $attempt->guid)->value('status'))->toBe('paid')
        ->and(DB::table('bookings')->value('status'))->toBe('confirmed')
        ->and(DB::table('payment_events')->where('event_type', 'capture')->count())->toBe(1);
});

it('keeps working for existing orders while new charges are switched off', function () {
    mtxConfig(['enabled' => false]);
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking);
    $this->postJson(MTX_WEBHOOK, mtxNotification($attempt))->assertOk();
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement'));

    mtxDrain();

    expect(DB::table('bookings')->value('status'))->toBe('confirmed');
});

it('fails closed with a retryable status when the server key is not configured', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking);
    $payload = mtxNotification($attempt);
    mtxConfig(['server_key' => '']);
    Http::fake();

    $this->postJson(MTX_WEBHOOK, $payload)->assertStatus(503);

    $this->assertDatabaseCount('payment_events', 0);
    Queue::assertNothingPushed();
});

it('is a public POST-only endpoint that ignores customer credentials', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking);

    $this->postJson(MTX_WEBHOOK, mtxNotification($attempt), ['Authorization' => 'Bearer junk'])->assertOk();
    $this->getJson(MTX_WEBHOOK)->assertStatus(405);
});

it('rate limits the public endpoint', function () {
    $payload = ['order_id' => 'x', 'status_code' => '200', 'gross_amount' => '1.00', 'signature_key' => 'bad', 'transaction_status' => 'settlement'];

    foreach (range(1, 300) as $i) {
        $this->postJson(MTX_WEBHOOK, $payload);
    }

    $this->postJson(MTX_WEBHOOK, $payload)->assertStatus(429);
});

it('lets a payment that is settled while the Charge response is still in flight survive the late response', function () {
    [$owner, $booking] = mtxPayable();
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/charge' => function (ClientRequest $request) {
            $attempt = DB::table('payment_attempts')->where('merchant_reference', $request['transaction_details']['order_id'])->first();
            // The signed notification arrives and is processed before the Charge response is stored.
            $payload = mtxNotification($attempt);
            app(MidtransPaymentService::class)->receiveNotification($payload, json_encode($payload));
            mtxDrain();
            $expiry = CarbonImmutable::parse($request['custom_expiry']['order_time'])->addMinutes($request['custom_expiry']['expiry_duration'])->setTimezone('Asia/Jakarta');

            return Http::response(['status_code' => '201', 'transaction_id' => 'trx-inflight', 'order_id' => $attempt->merchant_reference, 'merchant_id' => 'G000000001',
                'gross_amount' => '20000.00', 'currency' => 'IDR', 'payment_type' => 'bank_transfer', 'transaction_time' => mtxWib(), 'transaction_status' => 'pending',
                'fraud_status' => 'accept', 'expiry_time' => $expiry->format('Y-m-d H:i:s'), 'va_numbers' => [['bank' => 'bca', 'va_number' => '81234500001']]]);
        },
        'https://api.sandbox.midtrans.com/v2/*/status' => function (ClientRequest $request) {
            $attempt = DB::table('payment_attempts')->first();

            return Http::response(mtxStatusBody($attempt, 'settlement', ['transaction_id' => 'trx-inflight']));
        },
    ]);

    $data = $this->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts', ['method_code' => 'virtual_account', 'channel' => 'bca'],
        bearer($owner) + ['Idempotency-Key' => 'inflight'])->assertOk()->json('data');

    $attempt = DB::table('payment_attempts')->sole();
    expect($data['status'])->toBe('paid')->and($attempt->status)->toBe('paid')->and($attempt->provider_reference)->toBe('trx-inflight')
        ->and(DB::table('bookings')->value('status'))->toBe('confirmed')
        ->and(DB::table('payment_events')->where('event_type', 'capture')->count())->toBe(1);
});

it('keeps the receipt after a queue outage and replays it from the scheduler', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking);
    $this->instance(Dispatcher::class, Mockery::mock(Dispatcher::class, fn ($mock) => $mock->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'))));

    $this->postJson(MTX_WEBHOOK, mtxNotification($attempt))->assertOk();

    $receipt = DB::table('payment_events')->sole();
    expect($receipt->disposition)->toBe('received')->and(DB::table('bookings')->value('status'))->toBe('awaiting_payment');
    $this->app->forgetInstance(Dispatcher::class);
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement'));

    expect(Artisan::call('payments:reconcile-midtrans'))->toBe(0);
    mtxDrain();

    expect(DB::table('payment_events')->where('guid', $receipt->guid)->value('disposition'))->toBe('processed')
        ->and(DB::table('bookings')->value('status'))->toBe('confirmed');
});

it('never writes the payload, signature or server key to the log', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking);
    config(['logging.default' => 'mtx_capture', 'logging.channels.mtx_capture' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
    $payload = mtxNotification($attempt);

    // Always change the first hex digit; a fixed 'f' left the signature valid whenever it already started with 'f'.
    $tampered = ($payload['signature_key'][0] === 'f' ? 'e' : 'f').substr($payload['signature_key'], 1);
    $this->postJson(MTX_WEBHOOK, ['signature_key' => $tampered] + $payload)->assertUnauthorized();
    $this->postJson(MTX_WEBHOOK, $payload)->assertOk();
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement'));
    mtxDrain();

    $records = Log::channel('mtx_capture')->getHandlers()[0]->getRecords();
    expect($records)->not->toBeEmpty();
    foreach ($records as $record) {
        $encoded = $record->message.json_encode($record->context);
        expect($encoded)->not->toContain('SB-Mid-server')->not->toContain($payload['signature_key'])->not->toContain('81234500001')
            ->not->toContain($payload['gross_amount'])->not->toContain($payload['order_id']);
    }
});
