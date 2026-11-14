<?php

use App\Jobs\IssueBookingTicketQr;
use App\Jobs\ReconcileMidtransPayment;
use App\Services\MidtransPaymentService;
use App\Services\PaymentEligibility;
use App\Services\PaymentSettlementService;
use App\Services\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/Support/MidtransFixtures.php';

uses(RefreshDatabase::class);

/*
 * Status reconciliation, settlement application, scheduler recovery and the owner refresh endpoint. The provider is a fake and the
 * database is SQLite; real locking is covered by tests/Mysql/MidtransPaymentConcurrencyTest.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 08:00:00', 'UTC'));
    mtxConfig();
    Http::preventStrayRequests();
    Queue::fake();
});

function mtxDue(object $attempt): object
{
    DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['next_reconcile_at' => now()->subSecond()]);

    return DB::table('payment_attempts')->where('guid', $attempt->guid)->first();
}

function mtxRow(object $attempt): object
{
    return DB::table('payment_attempts')->where('guid', $attempt->guid)->first();
}

it('recovers a missing webhook: the scheduler finds the due attempt and confirms the booking once', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxDue(mtxAttempt($booking));
    $calls = mtxFakeStatus(mtxStatusBody($attempt, 'settlement'));

    expect(Artisan::call('payments:reconcile-midtrans'))->toBe(0);
    Queue::assertPushed(ReconcileMidtransPayment::class, 1);
    mtxDrain();
    mtxDrain();

    expect($calls->calls)->toBe(1)->and(DB::table('bookings')->value('status'))->toBe('confirmed')
        ->and(mtxRow($attempt)->status)->toBe('paid')->and(DB::table('payment_events')->where('event_type', 'capture')->count())->toBe(1);
    Queue::assertPushed(IssueBookingTicketQr::class, 1);
});

it('does not poll an attempt that is not due, and bounds every run', function () {
    [, $booking] = mtxPayable();
    $notDue = mtxAttempt($booking);
    Http::fake();

    Artisan::call('payments:reconcile-midtrans');

    Queue::assertNothingPushed();
    foreach (range(1, 5) as $i) {
        [, $other] = mtxPayable();
        mtxDue(mtxAttempt($other));
    }
    Artisan::call('payments:reconcile-midtrans', ['--limit' => 2]);
    Queue::assertPushed(ReconcileMidtransPayment::class, 2);
    Http::assertNothingSent();
    expect(mtxRow($notDue)->reconcile_count)->toBe(0);
});

it('survives a provider outage without paying, backs off and recovers on the same order id', function (mixed $failure, int $http) {
    [, $booking] = mtxPayable();
    $attempt = mtxDue(mtxAttempt($booking));
    mtxFakeStatus($failure, $http);

    app(MidtransPaymentService::class)->reconcile($attempt->guid);

    $row = mtxRow($attempt);
    expect($row->status)->toBe('pending')->and($row->last_error_code)->toBe('provider_unavailable')->and($row->reconcile_count)->toBe(1)
        ->and($row->instruction_ciphertext)->not->toBeNull()
        ->and(CarbonImmutable::parse($row->next_reconcile_at, 'UTC')->gt(now()->addSeconds(29)))->toBeTrue()
        ->and(CarbonImmutable::parse($row->next_reconcile_at, 'UTC')->lte(now()->addMinutes(6)))->toBeTrue()
        ->and(DB::table('bookings')->value('status'))->toBe('awaiting_payment');
    $this->travelTo(now()->addMinutes(7));
    $calls = mtxFakeStatus(mtxStatusBody($attempt, 'settlement'));
    app(MidtransPaymentService::class)->reconcile($attempt->guid);

    expect($calls->urls)->toBe([mtxStatusUrl($attempt)])->and(mtxRow($attempt)->status)->toBe('paid')->and(DB::table('bookings')->value('status'))->toBe('confirmed');
    Http::assertNotSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/v2/charge'));
})->with([
    'server error' => [['status_code' => '500'], 500],
    'rate limited' => [[], 429],
    'timeout' => [fn () => new ConnectionException('cURL error 28'), 200],
]);

it('collapses parallel workers into one provider lookup through a durable claim', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxDue(mtxAttempt($booking));
    $calls = mtxFakeStatus(mtxStatusBody($attempt, 'pending'));

    app(MidtransPaymentService::class)->reconcile($attempt->guid);
    app(MidtransPaymentService::class)->reconcile($attempt->guid);
    app(MidtransPaymentService::class)->reconcile($attempt->guid);

    expect($calls->calls)->toBe(1)->and(mtxRow($attempt)->reconcile_count)->toBe(1);
});

it('does nothing and makes no request when credentials are missing', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxDue(mtxAttempt($booking));
    mtxConfig(['server_key' => '']);
    Http::fake();

    Artisan::call('payments:reconcile-midtrans');
    app(MidtransPaymentService::class)->reconcile($attempt->guid);

    Http::assertNothingSent();
    expect(mtxRow($attempt)->reconcile_count)->toBe(0)->and(mtxRow($attempt)->status)->toBe('pending');
});

it('recovers a timed-out Charge: the same order is found pending at the provider and becomes payable without a second Charge', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxDue(mtxAttempt($booking, 'unknown', 'virtual_account', 'bca', ['provider_reference' => null, 'last_error_code' => 'provider_unavailable']));
    $calls = mtxFakeStatus(mtxStatusBody($attempt, 'pending', ['transaction_id' => 'trx-recovered']));

    app(MidtransPaymentService::class)->reconcile($attempt->guid);

    $row = mtxRow($attempt);
    expect($row->status)->toBe('pending')->and($row->provider_reference)->toBe('trx-recovered')->and($row->presentation_type)->toBe('virtual_account')
        ->and($row->last_error_code)->toBeNull()->and($row->merchant_reference)->toBe($attempt->merchant_reference)
        ->and($calls->urls)->toBe([mtxStatusUrl($attempt)])->and(DB::table('payment_attempts')->count())->toBe(1);
    Http::assertNotSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/v2/charge'));
});

it('keeps an order the provider does not know as unknown: no new order, no Charge, no release', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxDue(mtxAttempt($booking, 'initiating', 'virtual_account', 'bca'));
    mtxFakeStatus(['status_code' => '404', 'status_message' => "Transaction doesn't exist."]);

    app(MidtransPaymentService::class)->reconcile($attempt->guid);

    $row = mtxRow($attempt);
    expect($row->status)->toBe('unknown')->and($row->last_error_code)->toBe('provider_order_not_found')->and(DB::table('payment_attempts')->count())->toBe(1)
        ->and(DB::table('bookings')->value('status'))->toBe('awaiting_payment')->and(DB::table('payment_intents')->value('status'))->toBe('processing');
});

it('applies a settlement observed after the deadline that the provider timestamped before it', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxDue(mtxAttempt($booking));
    $deadline = CarbonImmutable::parse($booking->payment_expires_at, 'UTC');
    $this->travelTo($deadline->addMinutes(3));
    mtxDue($attempt);
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement', ['settlement_time' => mtxWib($deadline->subMinutes(2))]));

    app(MidtransPaymentService::class)->reconcile($attempt->guid);

    expect(DB::table('bookings')->value('status'))->toBe('confirmed')->and(mtxRow($attempt)->status)->toBe('paid');
});

it('sends a settlement that happened after the deadline to review, never to confirmation or tickets', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking);
    $deadline = CarbonImmutable::parse($booking->payment_expires_at, 'UTC');
    $this->travelTo($deadline->addMinutes(3));
    mtxDue($attempt);
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement', ['settlement_time' => mtxWib($deadline->addMinute())]));

    app(MidtransPaymentService::class)->reconcile($attempt->guid);

    expect(DB::table('bookings')->value('status'))->not->toBe('confirmed')->and(DB::table('payment_intents')->value('status'))->toBe('needs_review')
        ->and(DB::table('payment_events')->where('event_type', 'capture')->value('disposition'))->toBe('unapplied')
        ->and(app(PaymentEligibility::class)->status(app(TicketService::class)->booking($booking->guid)))->not->toBe('paid');
    Queue::assertNotPushed(IssueBookingTicketQr::class);
});

it('verifies an expiry without needing the provider timestamp to postdate the reservation', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxDue(mtxAttempt($booking));
    mtxFakeStatus(mtxStatusBody($attempt, 'expire', ['transaction_time' => mtxWib(now()->subMinutes(10))]));

    app(MidtransPaymentService::class)->reconcile($attempt->guid);

    expect(mtxRow($attempt)->status)->toBe('expired')->and(DB::table('payment_intents')->value('status'))->toBe('requires_payment')
        ->and(DB::table('payment_events')->where('event_type', 'status')->where('verification', 'verified')->value('disposition'))->toBe('applied')
        ->and(DB::table('bookings')->value('status'))->toBe('awaiting_payment');
});

it('closes an attempt whose Charge response was lost when the first successful lookup already reports provider expiry', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxDue(mtxAttempt($booking, 'initiating', 'virtual_account', 'bca', ['provider_reference' => null]));
    mtxFakeStatus(mtxStatusBody($attempt, 'expire', ['transaction_id' => 'trx-late-expiry']));

    app(MidtransPaymentService::class)->reconcile($attempt->guid);

    expect(mtxRow($attempt)->status)->toBe('expired')->and(mtxRow($attempt)->finished_at)->not->toBeNull()
        ->and(DB::table('payment_events')->where('event_type', 'status')->where('verification', 'verified')->value('disposition'))->toBe('applied')
        ->and(DB::table('payment_intents')->value('status'))->toBe('requires_payment');
});

it('sends a settlement for an attempt already closed as expired to review instead of auto-confirming it', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, 'expired', 'virtual_account', 'bca', ['finished_at' => now()]);
    $payload = mtxNotification($attempt);
    $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement'));

    mtxDrain();

    expect(DB::table('bookings')->value('status'))->not->toBe('confirmed')->and(DB::table('payment_intents')->value('status'))->toBe('needs_review')
        ->and(DB::table('payment_events')->where('event_type', 'capture')->value('disposition'))->toBe('unapplied');
});

it('does not let a later expiry or pending read undo a payment', function (string $state) {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking);
    $this->postJson('/api/v1/webhooks/midtrans', mtxNotification($attempt))->assertOk();
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement'));
    mtxDrain();
    $this->postJson('/api/v1/webhooks/midtrans', mtxNotification($attempt, $state))->assertOk();
    mtxFakeStatus(mtxStatusBody($attempt, $state));

    mtxDrain();

    expect(mtxRow($attempt)->status)->toBe('paid')->and(DB::table('bookings')->value('status'))->toBe('confirmed')
        ->and(DB::table('payment_intents')->value('status'))->toBe('paid');
})->with(['expire', 'pending', 'cancel']);

it('applies a verified full refund as a reversal that never reactivates entitlement', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking);
    $this->postJson('/api/v1/webhooks/midtrans', mtxNotification($attempt))->assertOk();
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement'));
    mtxDrain();
    $this->travelTo(now()->addMinutes(1));
    $this->postJson('/api/v1/webhooks/midtrans', mtxNotification($attempt, 'refund'))->assertOk();
    mtxFakeStatus(mtxStatusBody($attempt, 'refund'));
    mtxDrain();

    $fresh = app(TicketService::class)->booking($booking->guid);
    expect(mtxRow($attempt)->status)->toBe('reversed')->and(app(PaymentEligibility::class)->status($fresh))->toBe('reversed')
        ->and(app(PaymentEligibility::class)->entitlement($fresh))->not->toBe('paid');
    // A stale settlement read afterwards must not bring the payment back.
    $this->postJson('/api/v1/webhooks/midtrans', mtxNotification($attempt, 'settlement', ['gross_amount' => '20000.00', 'fraud_status' => 'accept']))->assertOk();
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement'));
    mtxDrain();

    expect(mtxRow($attempt)->status)->toBe('reversed')->and(app(PaymentEligibility::class)->entitlement(app(TicketService::class)->booking($booking->guid)))->not->toBe('paid')
        ->and(DB::table('payment_events')->where('event_type', 'capture')->count())->toBe(1);
});

it('routes ambiguous provider evidence to review without confirming anything', function (string $state, array $override) {
    [, $booking] = mtxPayable();
    $attempt = mtxDue(mtxAttempt($booking));
    mtxFakeStatus(mtxStatusBody($attempt, $state, $override));

    app(MidtransPaymentService::class)->reconcile($attempt->guid);

    expect(DB::table('payment_intents')->value('status'))->toBe('needs_review')->and(DB::table('bookings')->value('status'))->toBe('awaiting_payment')
        ->and(DB::table('payment_events')->where('event_type', 'capture')->count())->toBe(0)
        ->and(mtxRow($attempt)->status)->not->toBe('paid');
})->with([
    'fraud challenge' => ['settlement', ['fraud_status' => 'challenge']],
    'partial refund' => ['partial_refund', []],
    'chargeback' => ['chargeback', []],
    'wrong merchant' => ['settlement', ['merchant_id' => 'G999999999']],
]);

it('escalates an attempt still unresolved after its window exactly once and keeps holding the booking', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking);
    // A few minutes of lag is not escalated; a long silence is.
    $this->travelTo(CarbonImmutable::parse($booking->payment_expires_at, 'UTC')->addMinutes(31));
    Http::fake();
    Log::spy();

    Artisan::call('payments:reconcile-midtrans');
    Artisan::call('payments:reconcile-midtrans');

    $row = mtxRow($attempt);
    expect($row->escalated_at)->not->toBeNull()->and($row->status)->toBe('pending')->and(DB::table('bookings')->value('status'))->toBe('awaiting_payment');
    Log::shouldHaveReceived('error')->once();
});

it('never touches historical DOKU or DEMO attempts', function () {
    [, $booking] = mtxPayable();
    $doku = mtxAttempt($booking, 'pending', 'virtual_account', 'bni', ['provider' => 'doku', 'merchant_reference' => (string) Str::uuid(), 'merchant_id' => null]);
    DB::table('payment_intents')->where('guid', $doku->payment_intent_guid)->update(['provider' => 'doku']);
    [, $other] = mtxPayable();
    $demo = mtxAttempt($other, 'pending', 'demo', 'demo_simulator', ['provider' => 'demo', 'environment' => 'demo', 'merchant_reference' => (string) Str::uuid(), 'merchant_id' => null]);
    DB::table('payment_intents')->where('guid', $demo->payment_intent_guid)->update(['provider' => 'demo', 'environment' => 'demo']);
    mtxDue($doku);
    mtxDue($demo);
    Http::fake();

    Artisan::call('payments:reconcile-midtrans');
    app(MidtransPaymentService::class)->reconcile($doku->guid);
    app(MidtransPaymentService::class)->reconcile($demo->guid);

    Http::assertNothingSent();
    Queue::assertNothingPushed();
    expect(mtxRow($doku)->reconcile_count)->toBe(0)->and(mtxRow($demo)->reconcile_count)->toBe(0);
});

it('refreshes an owned open attempt through the authenticated lookup and returns the payment snapshot', function () {
    [$owner, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking);
    $calls = mtxFakeStatus(mtxStatusBody($attempt, 'settlement'));

    $response = $this->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts/'.$attempt->guid.'/refresh', [], bearer($owner))->assertOk();

    expect($calls->urls)->toBe([mtxStatusUrl($attempt)])->and($response->headers->get('Cache-Control'))->toContain('no-store');
    $response->assertJsonPath('data.status', 'paid')->assertJsonPath('data.last_attempt.status', 'paid')->assertJsonPath('data.payment_status', 'paid')
        ->assertJsonPath('data.provider', 'midtrans');
    expect(json_encode($response->json()))->not->toContain('SB-Mid-server')->not->toContain('api.sandbox.midtrans.com');
    Http::assertNotSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/v2/charge'));
    expect(DB::table('bookings')->value('status'))->toBe('confirmed');
});

it('refreshes while new charges are off and never falls back to DOKU', function () {
    mtxConfig(['enabled' => false]);
    [$owner, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking);
    mtxFakeStatus(mtxStatusBody($attempt, 'pending'));

    $this->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts/'.$attempt->guid.'/refresh', [], bearer($owner))->assertOk();

    Http::assertSent(fn (ClientRequest $request) => $request->url() === mtxStatusUrl($attempt));
    Http::assertNotSent(fn (ClientRequest $request) => str_contains($request->url(), 'doku'));
});

it('protects refresh with authentication and ownership', function () {
    [$owner, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking);
    [, $otherBooking] = mtxPayable();
    $otherAttempt = mtxAttempt($otherBooking);
    Http::fake();
    $path = fn (object $b, string $guid) => '/api/v1/bookings/'.$b->guid.'/payment-attempts/'.$guid.'/refresh';

    $this->postJson($path($booking, $attempt->guid))->assertUnauthorized();
    $this->postJson($path($booking, $attempt->guid), [], bearer(makeUser()))->assertNotFound();
    $this->postJson($path($booking, $attempt->guid), [], bearer(makeUser(1)))->assertNotFound();
    $this->postJson($path($booking, $otherAttempt->guid), [], bearer($owner))->assertNotFound();
    $this->postJson($path($booking, (string) Str::uuid()), [], bearer($owner))->assertNotFound();
    $this->postJson($path($booking, 'not-a-uuid'), [], bearer($owner))->assertNotFound();

    Http::assertNothingSent();
});

it('answers a quick second refresh without a second provider lookup and allows the next one later', function () {
    [$owner, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking);
    $calls = mtxFakeStatus(mtxStatusBody($attempt, 'pending'));
    $path = '/api/v1/bookings/'.$booking->guid.'/payment-attempts/'.$attempt->guid.'/refresh';

    $this->postJson($path, [], bearer($owner))->assertOk();
    $this->postJson($path, [], bearer($owner))->assertOk();
    expect($calls->calls)->toBe(1);
    $this->travelTo(now()->addSeconds(11));
    $this->postJson($path, [], bearer($owner))->assertOk();

    expect($calls->calls)->toBe(2);
});

it('rate limits refresh per user', function () {
    [$owner, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking);
    mtxFakeStatus(mtxStatusBody($attempt, 'pending'));
    $path = '/api/v1/bookings/'.$booking->guid.'/payment-attempts/'.$attempt->guid.'/refresh';

    $statuses = [];
    foreach (range(1, 24) as $i) {
        $statuses[] = $this->postJson($path, [], bearer($owner))->getStatusCode();
    }

    $first = array_search(429, $statuses, true);
    expect($first)->not->toBeFalse()->and($first)->toBeLessThanOrEqual(21)
        ->and(array_slice($statuses, $first))->each->toBe(429);
});

it('does not look anything up for a finished attempt', function () {
    [$owner, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, 'failed', 'virtual_account', 'bca', ['finished_at' => now()]);
    Http::fake();

    $this->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts/'.$attempt->guid.'/refresh', [], bearer($owner))->assertOk();

    Http::assertNothingSent();
});

it('processes a stored receipt only through the authenticated lookup and marks it processed once', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking);
    $payload = mtxNotification($attempt);
    $receiptGuid = app(MidtransPaymentService::class)->receiveNotification($payload, json_encode($payload));
    $calls = mtxFakeStatus(mtxStatusBody($attempt, 'pending'));

    app(MidtransPaymentService::class)->processEvent($receiptGuid);
    $this->travelTo(now()->addSeconds(30));
    app(MidtransPaymentService::class)->processEvent($receiptGuid);

    expect($calls->calls)->toBe(1)->and(DB::table('payment_events')->where('guid', $receiptGuid)->value('disposition'))->toBe('processed');
});

it('retries a receipt whose lookup failed with a lease, then gives up visibly', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking);
    $payload = mtxNotification($attempt);
    $receiptGuid = app(MidtransPaymentService::class)->receiveNotification($payload, json_encode($payload));
    mtxFakeStatus(['status_code' => '500'], 500);

    app(MidtransPaymentService::class)->processEvent($receiptGuid);

    $receipt = DB::table('payment_events')->where('guid', $receiptGuid)->first();
    expect($receipt->disposition)->toBe('received')->and($receipt->process_attempts)->toBe(1)
        ->and(CarbonImmutable::parse($receipt->claimed_until, 'UTC')->gt(now()))->toBeTrue();
    // Inside the lease the same receipt is not claimed again.
    $calls = mtxFakeStatus(mtxStatusBody($attempt, 'pending'));
    app(MidtransPaymentService::class)->processEvent($receiptGuid);
    expect($calls->calls)->toBe(0);
    $this->travelTo(now()->addMinutes(3));
    DB::table('payment_events')->where('guid', $receiptGuid)->update(['process_attempts' => 9, 'claimed_until' => null]);
    mtxFakeStatus(['status_code' => '500'], 500);
    app(MidtransPaymentService::class)->processEvent($receiptGuid);

    expect(DB::table('payment_events')->where('guid', $receiptGuid)->value('disposition'))->toBe('unprocessable');
});

it('replays a verified evidence event that was recorded but not applied', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking);
    $eventGuid = (string) Str::uuid();
    $outcome = ['status' => 'paid', 'merchant_reference' => $attempt->merchant_reference, 'provider_reference' => $attempt->provider_reference, 'amount' => 20000,
        'currency' => 'IDR', 'occurred_at' => now()->toIso8601String(), 'method' => 'virtual_account', 'channel' => 'bca'];
    DB::table('payment_events')->insert(['guid' => $eventGuid, 'payment_intent_guid' => $attempt->payment_intent_guid, 'payment_attempt_guid' => $attempt->guid,
        'provider' => 'midtrans', 'environment' => 'sandbox', 'provider_event_key' => 'orphaned', 'payload_hash' => hash('sha256', 'orphaned'),
        'verification' => 'verified', 'event_type' => 'capture', 'disposition' => 'recorded', 'amount' => 20000, 'currency' => 'IDR',
        'provider_occurred_at' => now(), 'received_at' => now(), 'metadata' => json_encode(['source' => 'midtrans_status', 'outcome' => $outcome])]);
    Http::fake();

    Artisan::call('payments:reconcile-midtrans');
    Artisan::call('payments:reconcile-midtrans');

    expect(DB::table('bookings')->value('status'))->toBe('confirmed')->and(DB::table('payment_events')->where('guid', $eventGuid)->value('disposition'))->toBe('applied');
    Http::assertNothingSent();
});

it('quarantines a lookup whose transaction id already belongs to another attempt', function () {
    [, $firstBooking] = mtxPayable();
    $first = mtxAttempt($firstBooking, 'pending', 'virtual_account', 'bca', ['provider_reference' => 'trx-shared']);
    [, $booking] = mtxPayable();
    $attempt = mtxDue(mtxAttempt($booking, 'initiating', 'virtual_account', 'bca'));
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement', ['transaction_id' => 'trx-shared']));

    app(MidtransPaymentService::class)->reconcile($attempt->guid);

    $row = mtxRow($attempt);
    expect($row->status)->not->toBe('paid')->and($row->provider_reference)->toBeNull()->and($row->last_error_code)->toBe('provider_reference_conflict')
        ->and(DB::table('payment_intents')->where('guid', $attempt->payment_intent_guid)->value('status'))->toBe('needs_review')
        ->and(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBe('awaiting_payment')
        ->and(DB::table('payment_events')->where('event_type', 'capture')->count())->toBe(0)
        ->and(mtxRow($first)->status)->toBe('pending')->and(mtxRow($first)->provider_reference)->toBe('trx-shared');
});

it('reviews a verified capture whose transaction id belongs to another attempt rather than raising a unique violation', function () {
    [, $firstBooking] = mtxPayable();
    mtxAttempt($firstBooking, 'pending', 'virtual_account', 'bca', ['provider_reference' => 'trx-shared']);
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, 'initiating', 'virtual_account', 'bca');
    $eventGuid = (string) Str::uuid();
    $outcome = ['status' => 'paid', 'merchant_reference' => $attempt->merchant_reference, 'provider_reference' => 'trx-shared', 'amount' => 20000,
        'currency' => 'IDR', 'occurred_at' => now()->toIso8601String(), 'method' => 'virtual_account', 'channel' => 'bca'];
    DB::table('payment_events')->insert(['guid' => $eventGuid, 'payment_intent_guid' => $attempt->payment_intent_guid, 'payment_attempt_guid' => $attempt->guid,
        'provider' => 'midtrans', 'environment' => 'sandbox', 'provider_event_key' => 'collision', 'payload_hash' => hash('sha256', 'collision'),
        'verification' => 'verified', 'event_type' => 'capture', 'disposition' => 'recorded', 'amount' => 20000, 'currency' => 'IDR',
        'provider_occurred_at' => now(), 'received_at' => now(), 'metadata' => json_encode(['source' => 'midtrans_status', 'outcome' => $outcome])]);

    app(PaymentSettlementService::class)->apply($eventGuid);

    expect(DB::table('payment_intents')->where('guid', $attempt->payment_intent_guid)->value('status'))->toBe('needs_review')
        ->and(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBe('awaiting_payment')
        ->and(mtxRow($attempt)->provider_reference)->toBeNull()->and(mtxRow($attempt)->status)->not->toBe('paid')
        ->and(DB::table('payment_events')->where('guid', $eventGuid)->value('disposition'))->toBe('quarantined');
});
