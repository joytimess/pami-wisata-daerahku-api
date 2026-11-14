<?php

use App\Services\BookingService;
use App\Support\BookingRules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/Support/MidtransFixtures.php';

uses(RefreshDatabase::class);

/*
 * The payment countdown starts when the customer picks the first method, not at approval. Approval only opens payment and sets a
 * 24 hour (never past the visit start) deadline to choose a method.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 08:00:00', 'UTC'));
    mtxConfig();
    Http::preventStrayRequests();
    Queue::fake();
});

function pcBooking(string $guid): object
{
    return DB::table('bookings')->where('guid', $guid)->first();
}

function pcHeld(): int
{
    return (int) DB::table('bookings')->whereIn('status', BookingRules::HELD)->sum('quantity');
}

it('approves without starting a countdown and exposes only the selection deadline', function () {
    [$owner, $booking] = mtxPayable(startCountdown: false);

    $row = pcBooking($booking->guid);
    expect($row->status)->toBe('awaiting_payment')
        ->and($row->payment_expires_at)->toBeNull()->and($row->notification_grace_expires_at)->toBeNull()
        ->and($row->payment_started_at)->toBeNull()
        ->and(CarbonImmutable::parse($row->payment_selection_expires_at, 'UTC')->equalTo(now()->addHours(24)))->toBeTrue();
    $this->getJson('/api/v1/payments/methods?booking_guid='.$booking->guid, bearer($owner))->assertOk()
        ->assertJsonPath('data.can_pay', true)->assertJsonPath('data.expires_at', null)
        ->assertJsonPath('data.selection_expires_at', now()->addHours(24)->toIso8601String());
    $zone = $row->destination_timezone_snapshot;
    expect($zone)->not->toBeNull();
    $this->getJson('/api/v1/payments/methods?booking_guid='.$booking->guid, bearer($owner))->assertJsonPath('data.timezone', $zone);
    $this->getJson('/api/v1/bookings/'.$booking->guid, bearer($owner))->assertOk()->assertJsonPath('data.destination_timezone', $zone);
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/payment-intent', bearer($owner))->assertOk()
        ->assertJsonPath('data.expires_at', null)->assertJsonPath('data.can_create', true);
});

it('keeps an approved booking awaiting payment for 20 minutes and for hours without a method', function () {
    [, $booking] = mtxPayable(startCountdown: false);

    $this->travel(20)->minutes();
    app(BookingService::class)->expireDue();
    expect(pcBooking($booking->guid)->status)->toBe('awaiting_payment');

    $this->travel(23)->hours();
    app(BookingService::class)->expireDue();
    expect(pcBooking($booking->guid)->status)->toBe('awaiting_payment');
});

it('starts the 15 minute countdown at the method choice, hours after approval', function () {
    [$owner, $booking] = mtxPayable(startCountdown: false);
    $charge = mtxFakeCharge();
    $this->travel(2)->hours();

    $response = mtxStart($owner, $booking)->assertOk();

    $row = pcBooking($booking->guid);
    $payload = $charge->payloads[0];
    expect(CarbonImmutable::parse($row->payment_started_at, 'UTC')->equalTo(now()))->toBeTrue()
        ->and(CarbonImmutable::parse($row->payment_expires_at, 'UTC')->equalTo(now()->addMinutes(15)))->toBeTrue()
        ->and(CarbonImmutable::parse($row->notification_grace_expires_at, 'UTC')->equalTo(now()->addMinutes(30)))->toBeTrue()
        ->and($row->payment_cycle)->toBe(1)
        ->and($response->json('data.expires_at'))->toBe(now()->addMinutes(15)->toIso8601String())
        ->and($payload['custom_expiry']['order_time'])->toBe(now()->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s O'))
        ->and($payload['custom_expiry']['expiry_duration'])->toBe(15)
        ->and(DB::table('payment_intents')->value('expires_at'))->toBe($row->payment_expires_at)
        ->and(DB::table('payment_attempts')->value('instruction_expires_at'))->toBe($row->payment_expires_at);
});

it('does not shift the deadline when the first attempt is replayed', function () {
    [$owner, $booking] = mtxPayable(startCountdown: false);
    mtxFakeCharge();
    mtxStart($owner, $booking, 'first')->assertOk();
    $deadline = pcBooking($booking->guid)->payment_expires_at;

    $this->travel(5)->minutes();
    mtxStart($owner, $booking, 'first')->assertOk();

    expect(pcBooking($booking->guid)->payment_expires_at)->toBe($deadline)->and(DB::table('payment_attempts')->count())->toBe(1);
});

it('expires a booking whose method was never chosen after the selection deadline and releases the quota', function () {
    [$owner, $booking] = mtxPayable(startCountdown: false);
    expect(pcHeld())->toBe(2);
    $this->travelTo(CarbonImmutable::parse(pcBooking($booking->guid)->payment_selection_expires_at, 'UTC'));

    app(BookingService::class)->expireDue();

    expect(pcBooking($booking->guid)->status)->toBe('expired')
        ->and(pcHeld())->toBe(0)
        ->and(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'expired')->value('reason'))->toBe('payment_method_not_selected')
        ->and(DB::table('notifications')->where('booking_guid', $booking->guid)->where('type', 'booking.expired')->count())->toBe(1);
    mtxStart($owner, $booking, 'too-late')->assertConflict();
    app(BookingService::class)->expireDue();
    expect(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'expired')->count())->toBe(1);
});

it('refuses to choose a method once the selection deadline has passed', function () {
    [$owner, $booking] = mtxPayable(startCountdown: false);
    $charge = mtxFakeCharge();
    $this->travelTo(CarbonImmutable::parse(pcBooking($booking->guid)->payment_selection_expires_at, 'UTC'));

    mtxStart($owner, $booking)->assertConflict()->assertJsonPath('code', 'payment_selection_closed');

    expect($charge->calls)->toBe(0)->and(pcBooking($booking->guid)->payment_started_at)->toBeNull();
});

it('never lets the selection or payment deadline pass the visit start', function () {
    $visitStart = CarbonImmutable::parse('2026-11-09 17:00:00', 'UTC');
    $started = CarbonImmutable::parse('2026-11-09 16:50:00', 'UTC');

    expect(BookingRules::paymentSelectionDeadline(CarbonImmutable::parse('2026-11-08 18:00:00', 'UTC'), $visitStart)->equalTo($visitStart))->toBeTrue()
        ->and(BookingRules::paymentDeadlineFrom($started, $visitStart, 'midtrans')->equalTo($visitStart))->toBeTrue()
        ->and(BookingRules::paymentDeadlineFrom($started->subHours(1), $visitStart, 'midtrans')->equalTo($started->subHours(1)->addMinutes(15)))->toBeTrue();
});

it('keeps a Midtrans booking awaiting payment after the deadline while its attempt is unresolved', function () {
    [$owner, $booking] = mtxPayable(startCountdown: false);
    mtxFakeCharge();
    mtxStart($owner, $booking)->assertOk();
    $this->travelTo(CarbonImmutable::parse(pcBooking($booking->guid)->payment_expires_at, 'UTC')->addSecond());

    app(BookingService::class)->expireDue();

    expect(pcBooking($booking->guid)->status)->toBe('awaiting_payment');
});

it('uses the booking timezone snapshot, not the live destination timezone, for the approval lead time', function () {
    [$destination, $date] = seedDestination(capacity: 10, price: 10000, days: 2, destination: ['timezone' => 'Asia/Jakarta']);
    $owner = makeUser();
    $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date, 1) + ['idempotency_key' => 'tz-snapshot']);
    // The live destination zone is corrected after the booking exists; the booking keeps its own.
    DB::table('destinations')->where('guid', $destination->guid)->update(['timezone' => 'Asia/Jayapura']);

    $approved = app(BookingService::class)->decide(makeUser(2), $booking->guid, true);
    $visitStart = BookingRules::visitStart($date, 'Asia/Jakarta');

    expect(CarbonImmutable::parse(pcBooking($approved->guid)->payment_selection_expires_at, 'UTC')->lte($visitStart))->toBeTrue();
});

it('reports a booking awaiting approval as awaiting_approval, never awaiting_payment', function () {
    [$destination, $date] = seedDestination(capacity: 10, price: 10000, days: 5);
    $owner = makeUser();
    $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date, 1) + ['idempotency_key' => 'ticket-state']);

    $this->getJson("/api/v1/bookings/{$booking->guid}/tickets", bearer($owner))->assertOk()->assertJsonPath('data.ticket_state', 'awaiting_approval');
});

it('tells the customer to pick a payment method before the selection deadline in the destination zone label', function () {
    [, $booking] = mtxPayable(startCountdown: false);

    $notification = DB::table('notifications')->where('booking_guid', $booking->guid)->where('type', 'booking.approved_awaiting_payment')->first();

    expect($notification)->not->toBeNull()
        ->and($notification->body)->toContain('pilih metode pembayaran sebelum')->toContain('9 November 2026 15:00 WIB');
});

it('brings the provider lookup forward after the deadline and keeps the booking awaiting payment while the attempt is pending', function () {
    [$owner, $booking] = mtxPayable(startCountdown: false);
    mtxFakeCharge();
    mtxStart($owner, $booking)->assertOk();
    $attempt = DB::table('payment_attempts')->first();
    DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['next_reconcile_at' => now()->addMinutes(5)]);
    $this->travelTo(CarbonImmutable::parse(pcBooking($booking->guid)->payment_expires_at, 'UTC')->addSecond());

    app(BookingService::class)->expireDue();

    expect(pcBooking($booking->guid)->status)->toBe('awaiting_payment')
        ->and(CarbonImmutable::parse(DB::table('payment_attempts')->value('next_reconcile_at'), 'UTC')->lte(now()))->toBeTrue();
});

it('reviews a settlement of an abandoned cycle that arrives after the reset instead of confirming it', function () {
    [$owner, $booking] = mtxPayable(startCountdown: false);
    mtxFakeCharge();
    mtxStart($owner, $booking)->assertOk();
    $attempt = DB::table('payment_attempts')->first();
    $deadline = CarbonImmutable::parse(pcBooking($booking->guid)->payment_expires_at, 'UTC');
    DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['status' => 'expired', 'finished_at' => now()]);
    $this->travelTo($deadline->addSecond());
    app(BookingService::class)->expireDue();
    expect(pcBooking($booking->guid)->payment_expires_at)->toBeNull();
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement', ['settlement_time' => mtxWib($deadline->subMinute())]));

    $this->postJson('/api/v1/webhooks/midtrans', mtxNotification($attempt, 'settlement'))->assertOk();
    mtxDrain();

    expect(pcBooking($booking->guid)->status)->toBe('awaiting_payment')
        ->and(DB::table('payment_intents')->value('status'))->toBe('needs_review')
        ->and(DB::table('payment_events')->where('event_type', 'capture')->value('disposition'))->not->toBe('applied');
});
