<?php

use App\Jobs\IssueBookingTicketQr;
use App\Services\BookingService;
use App\Services\MidtransPaymentService;
use App\Support\BookingRules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/Support/MidtransFixtures.php';

uses(RefreshDatabase::class);

/*
 * Phase 4 A-C: the one absolute 15 minute deadline, safe terminal-unpaid expiry, and strictly sequential attempts. The provider is a
 * fake; real locking is covered by tests/Mysql/MidtransPaymentConcurrencyTest.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 08:00:00', 'UTC'));
    mtxConfig();
    Http::preventStrayRequests();
    Queue::fake();
});

function mtxHeld(): int
{
    return (int) DB::table('bookings')->whereIn('status', BookingRules::HELD)->sum('quantity');
}

function mtxStatusOf(object $booking): string
{
    return DB::table('bookings')->where('guid', $booking->guid)->value('status');
}

function mtxPastDeadline(object $booking, int $seconds = 1): void
{
    test()->travelTo(CarbonImmutable::parse($booking->payment_expires_at, 'UTC')->addSeconds($seconds));
}

it('locks the deadline to the authoritative approval time plus exactly 900 seconds', function () {
    [, $booking] = mtxPayable();
    $approvedAt = mtxApprovedAt($booking);

    expect($approvedAt->equalTo(now()))->toBeTrue()
        ->and(CarbonImmutable::parse($booking->payment_expires_at, 'UTC')->equalTo($approvedAt->addSeconds(900)))->toBeTrue()
        ->and(CarbonImmutable::parse($booking->notification_grace_expires_at, 'UTC')->equalTo($approvedAt->addSeconds(900 + 900)))->toBeTrue()
        ->and($booking->status)->toBe('awaiting_payment')
        ->and(DB::table('invoices')->where('booking_guid', $booking->guid)->exists())->toBeTrue()
        ->and(mtxHeld())->toBe(2);
});

it('approves a free booking straight to confirmed without a deadline, an intent or any Midtrans call', function () {
    [$destination, $date] = seedDestination(capacity: 10, price: 0, days: 5);
    $owner = makeUser();
    $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date, 1) + ['idempotency_key' => (string) Str::uuid()]);
    $booking = app(BookingService::class)->decide(makeUser(2), $booking->guid, true);

    mtxStart($owner, $booking)->assertConflict();

    expect($booking->status)->toBe('confirmed')->and($booking->payment_expires_at)->toBeNull()
        ->and(DB::table('payment_intents')->count())->toBe(0);
    Http::assertNothingSent();
});

it('keeps the booking deadline for a retry made at minute 8', function () {
    [$owner, $booking] = mtxPayable();
    $this->travelTo(now()->addMinutes(8));
    $charge = mtxFakeCharge();

    $response = mtxStart($owner, $booking)->assertOk();

    $approvedAt = mtxApprovedAt($booking);
    $payload = $charge->payloads[0];
    // order_time is this attempt's own start; the duration shrinks so the provider still expires at the cycle deadline.
    expect($payload['custom_expiry']['order_time'])->toBe(now()->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s O'))
        ->and($payload['custom_expiry']['expiry_duration'])->toBe(7)
        ->and($response->json('data.expires_at'))->toBe($approvedAt->addSeconds(900)->toIso8601String())
        ->and(DB::table('payment_attempts')->value('instruction_expires_at'))->toBe($booking->payment_expires_at);
});

it('closes the payment window exactly at minute 15 and a little earlier by the minimum remaining window', function () {
    [$owner, $booking] = mtxPayable();
    $charge = mtxFakeCharge();

    $this->travelTo(now()->addSeconds(900 - 31));
    $this->getJson('/api/v1/payments/methods?booking_guid='.$booking->guid, bearer($owner))->assertOk()->assertJsonPath('data.can_pay', true);

    $this->travelTo(CarbonImmutable::parse($booking->payment_expires_at, 'UTC')->subSeconds(30));
    mtxStart($owner, $booking, 'late-guard')->assertConflict()->assertJsonPath('code', 'payment_window_closed');
    expect($charge->calls)->toBe(0)->and(DB::table('payment_attempts')->count())->toBe(0);

    $this->travelTo(CarbonImmutable::parse($booking->payment_expires_at, 'UTC'));
    $this->getJson('/api/v1/payments/methods?booking_guid='.$booking->guid, bearer($owner))->assertOk()
        ->assertJsonPath('data.can_pay', false)->assertJsonPath('data.can_create', false)->assertJsonPath('data.reason', 'payment_window_closed');
    mtxStart($owner, $booking, 'at-cutoff')->assertConflict()->assertJsonPath('code', 'payment_window_closed');
    expect($charge->calls)->toBe(0);
});

it('honours a configured minimum remaining window before any Charge', function () {
    mtxConfig(['minimum_expiry_seconds' => 120]);
    [$owner, $booking] = mtxPayable();
    $charge = mtxFakeCharge();
    $this->travelTo(CarbonImmutable::parse($booking->payment_expires_at, 'UTC')->subSeconds(100));

    mtxStart($owner, $booking)->assertConflict()->assertJsonPath('code', 'payment_window_closed');

    expect($charge->calls)->toBe(0)->and(DB::table('payment_attempts')->count())->toBe(0);
});

it('expires a booking that never started a payment at the deadline and releases its quota atomically', function () {
    [$owner, $booking] = mtxPayable();
    expect(mtxHeld())->toBe(2);
    mtxPastDeadline($booking);

    app(BookingService::class)->expireDue();

    expect(mtxStatusOf($booking))->toBe('expired')->and(mtxHeld())->toBe(0)
        ->and(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'expired')->count())->toBe(1);
    mtxStart($owner, $booking)->assertConflict();
    app(BookingService::class)->expireDue();
    expect(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'expired')->count())->toBe(1);
});

it('keeps the quota of a booking whose attempt is still open at the deadline', function (string $state) {
    [, $booking] = mtxPayable();
    mtxAttempt($booking, $state);
    mtxPastDeadline($booking);

    app(BookingService::class)->expireDue();

    // An unresolved Midtrans attempt keeps the quota and never moves to verification or review on a clock.
    expect(mtxStatusOf($booking))->toBe('awaiting_payment')->and(mtxHeld())->toBe(2);
    $this->travelTo(CarbonImmutable::parse($booking->notification_grace_expires_at, 'UTC')->addSecond());
    app(BookingService::class)->expireDue();
    expect(mtxStatusOf($booking))->toBe('awaiting_payment')->and(mtxHeld())->toBe(2)
        ->and(DB::table('notifications')->where('booking_guid', $booking->guid)->where('type', 'booking.payment_review')->count())->toBe(0);
})->with(['initiating', 'pending', 'unknown']);

it('releases the quota in the same step once every attempt is proven terminal unpaid and no method can be chosen any more', function () {
    [, $booking] = mtxPayable();
    mtxAttempt($booking, 'failed', 'virtual_account', 'bca', ['finished_at' => now()]);
    DB::table('payment_intents')->where('booking_guid', $booking->guid)->update(['status' => 'requires_payment']);
    DB::table('bookings')->where('guid', $booking->guid)->update(['payment_selection_expires_at' => now()->addMinutes(5)]);
    mtxPastDeadline($booking);

    app(BookingService::class)->expireDue();

    expect(mtxStatusOf($booking))->toBe('expired')->and(mtxHeld())->toBe(0)
        ->and(DB::table('payment_intents')->value('status'))->toBe('expired');
});

it('starts the payment cycle over instead of reviewing once every attempt is proven terminal unpaid', function () {
    [$owner, $booking] = mtxPayable();
    mtxAttempt($booking, 'failed', 'virtual_account', 'bca', ['finished_at' => now()]);
    DB::table('payment_intents')->where('booking_guid', $booking->guid)->update(['status' => 'requires_payment']);
    mtxPastDeadline($booking);

    app(BookingService::class)->expireDue();

    $row = DB::table('bookings')->where('guid', $booking->guid)->first();
    expect($row->status)->toBe('awaiting_payment')->and(mtxHeld())->toBe(2)
        ->and($row->payment_expires_at)->toBeNull()->and($row->payment_started_at)->toBeNull()->and($row->notification_grace_expires_at)->toBeNull()
        ->and(DB::table('payment_intents')->value('status'))->toBe('requires_payment')
        ->and(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('reason', 'payment_cycle_reset')->count())->toBe(1)
        ->and(DB::table('notifications')->where('booking_guid', $booking->guid)->where('type', 'booking.payment_cycle_expired')->count())->toBe(1)
        ->and(DB::table('notifications')->where('booking_guid', $booking->guid)->where('type', 'booking.payment_review')->count())->toBe(0);
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/payment-intent', bearer($owner))->assertOk()
        ->assertJsonPath('data.expires_at', null)->assertJsonPath('data.can_create', true);

    // A second cycle: a new method choice gets a fresh 15 minutes and a higher cycle number.
    $charge = mtxFakeCharge();
    mtxStart($owner, DB::table('bookings')->where('guid', $booking->guid)->first(), 'cycle-2', 'qris', 'qris')->assertOk();
    $again = DB::table('bookings')->where('guid', $booking->guid)->first();
    expect((int) $again->payment_cycle)->toBe(2)
        ->and(CarbonImmutable::parse($again->payment_expires_at, 'UTC')->equalTo(now()->addMinutes(15)))->toBeTrue()
        ->and(DB::table('payment_intents')->value('expires_at'))->toBe($again->payment_expires_at)
        ->and((int) DB::table('payment_attempts')->where('attempt_number', 2)->value('payment_cycle'))->toBe(2)
        ->and($charge->calls)->toBe(1);
});

it('expires the booking when the Status API proves a pending attempt terminal after the deadline', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, 'pending');
    mtxPastDeadline($booking);
    app(BookingService::class)->expireDue();
    expect(mtxStatusOf($booking))->toBe('awaiting_payment');
    DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['next_reconcile_at' => now()->subSecond()]);
    $calls = mtxFakeStatus(mtxStatusBody($attempt, 'expire'));

    app(MidtransPaymentService::class)->reconcile($attempt->guid);

    // The provider proved the attempt unpaid: the cycle starts over and the quota stays with the customer's open selection window.
    expect($calls->calls)->toBe(1)->and(mtxStatusOf($booking))->toBe('awaiting_payment')->and(mtxHeld())->toBe(2)
        ->and(DB::table('payment_attempts')->where('guid', $attempt->guid)->value('status'))->toBe('expired')
        ->and(DB::table('payment_intents')->value('status'))->toBe('requires_payment')
        ->and(DB::table('bookings')->where('guid', $booking->guid)->value('payment_expires_at'))->toBeNull();
    Queue::assertNotPushed(IssueBookingTicketQr::class);
});

it('never treats a 404, a timeout or an ambiguous Status answer as proof of non-payment', function (string $answer) {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, 'pending');
    mtxPastDeadline($booking);
    app(BookingService::class)->expireDue();
    DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['next_reconcile_at' => now()->subSecond()]);
    mtxFakeStatus(match ($answer) {
        'order not found' => ['status_code' => '404', 'status_message' => 'Transaction does not exist.'],
        'timeout' => new ConnectionException('timeout'),
        'garbled' => ['status_code' => '200', 'transaction_status' => 'something-new', 'order_id' => $attempt->merchant_reference],
    });

    app(MidtransPaymentService::class)->reconcile($attempt->guid);
    app(BookingService::class)->expireDue();

    expect(mtxStatusOf($booking))->toBe('awaiting_payment')->and(mtxHeld())->toBe(2)
        ->and(DB::table('payment_attempts')->where('guid', $attempt->guid)->value('status'))->toBeIn(['pending', 'unknown']);
})->with(['order not found', 'timeout', 'garbled']);

it('confirms a settlement that happened before the deadline even though it is learned afterwards', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, 'pending');
    mtxPastDeadline($booking, 120);
    app(BookingService::class)->expireDue();
    expect(mtxStatusOf($booking))->toBe('awaiting_payment');
    DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['next_reconcile_at' => now()->subSecond()]);
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement', ['settlement_time' => mtxWib(CarbonImmutable::parse($booking->payment_expires_at, 'UTC')->subMinutes(2))]));

    app(MidtransPaymentService::class)->reconcile($attempt->guid);

    expect(mtxStatusOf($booking))->toBe('confirmed')->and(DB::table('payment_intents')->value('status'))->toBe('paid');
    Queue::assertPushed(IssueBookingTicketQr::class, 1);
});

it('sends a settlement at or after the deadline to review without confirming or issuing anything', function (int $offset) {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, 'pending');
    mtxPastDeadline($booking, 600);
    app(BookingService::class)->expireDue();
    DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['next_reconcile_at' => now()->subSecond()]);
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement', ['settlement_time' => mtxWib(CarbonImmutable::parse($booking->payment_expires_at, 'UTC')->addSeconds($offset))]));

    app(MidtransPaymentService::class)->reconcile($attempt->guid);

    expect(mtxStatusOf($booking))->toBe('awaiting_payment')->and(mtxHeld())->toBe(2)
        ->and(DB::table('payment_intents')->value('status'))->toBe('needs_review')
        ->and(DB::table('payment_events')->where('event_type', 'capture')->value('disposition'))->toBe('unapplied');
    Queue::assertNotPushed(IssueBookingTicketQr::class);
})->with([0, 5, 300]);

it('does not trust a settlement without a provable timestamp', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, 'pending');
    mtxPastDeadline($booking, 60);
    app(BookingService::class)->expireDue();
    DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['next_reconcile_at' => now()->subSecond()]);
    $body = mtxStatusBody($attempt, 'settlement');
    unset($body['settlement_time']);
    mtxFakeStatus($body);

    app(MidtransPaymentService::class)->reconcile($attempt->guid);

    expect(mtxStatusOf($booking))->toBe('awaiting_payment')->and(DB::table('payment_events')->where('event_type', 'capture')->count())->toBe(0);
    Queue::assertNotPushed(IssueBookingTicketQr::class);
});

it('reviews a late settlement of an already released booking instead of resurrecting it', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, 'pending');
    mtxPastDeadline($booking);
    app(BookingService::class)->expireDue();
    DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['next_reconcile_at' => now()->subSecond()]);
    mtxFakeStatus(mtxStatusBody($attempt, 'expire'));
    app(MidtransPaymentService::class)->reconcile($attempt->guid);
    expect(mtxStatusOf($booking))->toBe('awaiting_payment')->and(mtxHeld())->toBe(2);

    $this->travelTo(now()->addMinute());
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement', ['settlement_time' => mtxWib(CarbonImmutable::parse($booking->payment_expires_at, 'UTC')->subMinute())]));
    $this->postJson('/api/v1/webhooks/midtrans', mtxNotification($attempt, 'settlement'))->assertOk();
    mtxDrain();

    // Evidence of an abandoned cycle is a real anomaly: it is reviewed, never confirmed.
    expect(mtxStatusOf($booking))->toBe('awaiting_payment')
        ->and(DB::table('payment_intents')->value('status'))->toBe('needs_review')
        ->and(DB::table('payment_events')->where('event_type', 'capture')->value('disposition'))->toBeIn(['unapplied', 'quarantined']);
    Queue::assertNotPushed(IssueBookingTicketQr::class);
});

it('decides settlement versus expiry the same way in either order', function () {
    // Settlement first: expiry has nothing left to close.
    [, $paid] = mtxPayable();
    $attempt = mtxAttempt($paid, 'pending');
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement', ['settlement_time' => mtxWib(now()->addMinutes(10))]));
    $this->travelTo(now()->addMinutes(10));
    DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['next_reconcile_at' => now()->subSecond()]);
    app(MidtransPaymentService::class)->reconcile($attempt->guid);
    mtxPastDeadline($paid);
    app(BookingService::class)->expireDue();
    expect(mtxStatusOf($paid))->toBe('confirmed')->and(DB::table('payment_intents')->where('booking_guid', $paid->guid)->value('status'))->toBe('paid');

    // Expiry first (all attempts proven unpaid): a later settlement cannot confirm and cannot overbook.
    [, $closed] = mtxPayable();
    $failed = mtxAttempt($closed, 'failed', 'virtual_account', 'bca', ['finished_at' => now()]);
    DB::table('payment_intents')->where('booking_guid', $closed->guid)->update(['status' => 'requires_payment']);
    mtxPastDeadline($closed);
    app(BookingService::class)->expireDue();
    expect(mtxStatusOf($closed))->toBe('awaiting_payment');
    mtxFakeStatus(mtxStatusBody($failed, 'settlement', ['settlement_time' => mtxWib(CarbonImmutable::parse($closed->payment_expires_at, 'UTC')->subMinute())]));
    $this->travelTo(now()->addMinute());
    $this->postJson('/api/v1/webhooks/midtrans', mtxNotification($failed, 'settlement'))->assertOk();
    mtxDrain();
    expect(mtxStatusOf($closed))->toBe('awaiting_payment')->and(DB::table('payment_intents')->where('booking_guid', $closed->guid)->value('status'))->toBe('needs_review');
});

it('does not close a Midtrans booking while the intent is under review or an attempt is paid', function () {
    [, $booking] = mtxPayable();
    mtxAttempt($booking, 'failed', 'virtual_account', 'bca', ['finished_at' => now()]);
    DB::table('payment_intents')->where('booking_guid', $booking->guid)->update(['status' => 'needs_review']);
    mtxPastDeadline($booking);

    app(BookingService::class)->expireDue();

    expect(mtxStatusOf($booking))->toBe('awaiting_payment')->and(mtxHeld())->toBe(2);
});

it('replays the same attempt for the same key and refuses the same key with a different request', function () {
    [$owner, $booking] = mtxPayable();
    $charge = mtxFakeCharge();

    $first = mtxStart($owner, $booking, 'same-key')->assertOk();
    $replay = mtxStart($owner, $booking, 'same-key')->assertOk();
    mtxStart($owner, $booking, 'same-key', 'virtual_account', 'bni')->assertConflict()->assertJsonPath('code', 'idempotency_conflict');

    expect($replay->json('data.attempt_guid'))->toBe($first->json('data.attempt_guid'))->and($charge->calls)->toBe(1)
        ->and(DB::table('payment_attempts')->count())->toBe(1);
});

it('never creates a second payable attempt while the first one is unresolved, whatever key or method is used', function (string $state) {
    [$owner, $booking] = mtxPayable();
    $open = mtxAttempt($booking, $state);
    $charge = mtxFakeCharge();

    $response = mtxStart($owner, $booking, 'another-key', 'virtual_account', 'bni')->assertOk();
    mtxStart($owner, $booking, 'third-key', 'qris', 'qris')->assertOk();

    expect($response->json('data.attempt_guid'))->toBe($open->guid)->and($charge->calls)->toBe(0)
        ->and(DB::table('payment_attempts')->count())->toBe(1);
})->with(['initiating', 'pending', 'unknown']);

it('allows a retry only after the previous attempt is proven terminal, with the deadline unchanged', function () {
    [$owner, $booking] = mtxPayable();
    $charge = mtxFakeCharge();
    $first = mtxStart($owner, $booking, 'first')->assertOk()->json('data');
    $attempt = DB::table('payment_attempts')->where('guid', $first['attempt_guid'])->first();
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/payment-intent', bearer($owner))->assertOk()
        ->assertJsonPath('data.can_create', false)->assertJsonPath('data.can_switch', false);

    $this->travelTo(now()->addMinutes(8));
    mtxFakeStatus(mtxStatusBody($attempt, 'deny'));
    DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['next_reconcile_at' => now()->subSecond()]);
    app(MidtransPaymentService::class)->reconcile($attempt->guid);
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/payment-intent', bearer($owner))->assertOk()
        ->assertJsonPath('data.can_create', true)->assertJsonPath('data.can_switch', true);

    $second = mtxStart($owner, $booking, 'second', 'virtual_account', 'bni')->assertOk();

    $rows = DB::table('payment_attempts')->orderBy('attempt_number')->get();
    expect($rows)->toHaveCount(2)->and($rows[1]->attempt_number)->toBe(2)->and($rows[1]->channel)->toBe('bni')
        ->and($rows[1]->instruction_expires_at)->toBe($booking->payment_expires_at)
        ->and($rows[1]->merchant_reference)->not->toBe($rows[0]->merchant_reference)
        ->and($second->json('data.expires_at'))->toBe(mtxApprovedAt($booking)->addSeconds(900)->toIso8601String())
        ->and($charge->calls)->toBe(2)
        // Each attempt sends its own start as order_time and expires at the same absolute cycle deadline.
        ->and($charge->payloads[1]['custom_expiry']['order_time'])->not->toBeEmpty()
        ->and($charge->payloads[1]['custom_expiry']['expiry_duration'])->toBeLessThanOrEqual($charge->payloads[0]['custom_expiry']['expiry_duration']);
});

it('does not let a settlement of the abandoned earlier attempt confirm the booking after a retry', function () {
    [, $booking] = mtxPayable();
    $old = mtxAttempt($booking, 'pending');
    DB::table('payment_attempts')->where('guid', $old->guid)->update(['status' => 'failed', 'finished_at' => now()]);
    mtxAttempt($booking, 'pending', 'virtual_account', 'bni');
    $this->travelTo(now()->addMinutes(3));
    mtxFakeStatus(mtxStatusBody($old, 'settlement', ['settlement_time' => mtxWib(now()->subMinute())]));
    $this->postJson('/api/v1/webhooks/midtrans', mtxNotification($old, 'settlement'))->assertOk();
    $this->travelTo(now()->addMinute());
    mtxDrain();

    expect(mtxStatusOf($booking))->toBe('awaiting_payment')->and(DB::table('payment_intents')->value('status'))->toBe('needs_review');
    Queue::assertNotPushed(IssueBookingTicketQr::class);
});

it('keeps legacy DOKU and DEMO intents on the verification path instead of auto expiring them', function (string $provider, string $environment) {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, 'pending');
    DB::table('payment_intents')->where('booking_guid', $booking->guid)->update(['provider' => $provider, 'environment' => $environment]);
    DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['status' => 'failed', 'provider' => $provider, 'environment' => $environment, 'finished_at' => now()]);
    mtxPastDeadline($booking);

    app(BookingService::class)->expireDue();

    expect(mtxStatusOf($booking))->toBe('payment_verification')->and(mtxHeld())->toBe(2);
})->with([['doku', 'sandbox'], ['demo', 'demo']]);

it('escalates the same invalid Status evidence only across backed-off scheduled rounds and never from refresh bursts', function (string $mode, bool $escalates) {
    [$owner, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, 'pending');
    mtxFakeStatus(['status_code' => '200', 'transaction_status' => 'something-new', 'order_id' => $attempt->merchant_reference]);

    foreach (range(1, 9) as $round) {
        $this->travelTo(now()->addSeconds(40));
        DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['next_reconcile_at' => now()->subSecond()]);
        $mode === 'scheduled'
            ? app(MidtransPaymentService::class)->reconcile($attempt->guid)
            : app(MidtransPaymentService::class)->refresh($owner, $booking->guid, $attempt->guid);
    }

    expect(DB::table('payment_intents')->value('status') === 'needs_review')->toBe($escalates)
        ->and(DB::table('payment_attempts')->where('guid', $attempt->guid)->value('status'))->toBeIn(['pending', 'unknown']);
})->with([['scheduled', true], ['refresh', false]]);
