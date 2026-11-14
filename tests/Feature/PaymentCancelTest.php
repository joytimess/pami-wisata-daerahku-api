<?php

use App\Services\BookingService;
use App\Support\BookingRules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/Support/MidtransFixtures.php';

uses(RefreshDatabase::class);

/*
 * The owner can cancel a pending payment method (wrong choice) and cancel a booking that is awaiting payment. The provider's cancel
 * answer is never trusted alone: the verified Status lookup that follows decides, and a payment made meanwhile always wins.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 08:00:00', 'UTC'));
    mtxConfig();
    Http::preventStrayRequests();
    Queue::fake();
});

function pcxFakeCancel(array $body = ['status_code' => '200', 'transaction_status' => 'cancel']): object
{
    $counter = (object) ['calls' => 0];
    Http::fake(['https://api.sandbox.midtrans.com/v2/*/cancel' => function (Request $request) use ($counter, $body) {
        $counter->calls++;

        return Http::response($body + ['order_id' => basename(dirname($request->url()))], 200);
    }]);

    return $counter;
}

function pcxCancel(object $owner, object $booking, string $attemptGuid)
{
    return test()->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts/'.$attemptGuid.'/cancel', [], bearer($owner));
}

function pcxBookingCancel(object $owner, object $booking)
{
    return test()->postJson('/api/v1/bookings/'.$booking->guid.'/cancel', [], bearer($owner));
}

function pcxHeld(): int
{
    return (int) DB::table('bookings')->whereIn('status', BookingRules::HELD)->sum('quantity');
}

it('cancels a pending method, keeps the deadline and lets the owner pick another method', function () {
    [$owner, $booking] = mtxPayable(startCountdown: false);
    mtxFakeCharge();
    mtxStart($owner, $booking, 'first')->assertOk();
    $attempt = DB::table('payment_attempts')->first();
    $deadline = DB::table('bookings')->where('guid', $booking->guid)->value('payment_expires_at');
    $cancel = pcxFakeCancel();
    mtxFakeStatus(mtxStatusBody($attempt, 'cancel'));
    $this->travel(3)->minutes();

    pcxCancel($owner, $booking, $attempt->guid)->assertOk()->assertJsonPath('data.last_attempt.status', 'failed')
        ->assertJsonPath('data.can_create', true)->assertJsonPath('data.can_switch', true);

    expect($cancel->calls)->toBe(1)
        ->and(DB::table('payment_attempts')->where('guid', $attempt->guid)->value('last_error_code'))->toBe('provider_cancel')
        ->and(DB::table('payment_intents')->value('status'))->toBe('requires_payment')
        ->and(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBe('awaiting_payment')
        ->and(DB::table('bookings')->where('guid', $booking->guid)->value('payment_expires_at'))->toBe($deadline)
        ->and(DB::table('payment_events')->where('payment_attempt_guid', $attempt->guid)->where('disposition', 'cancel_requested')->count())->toBe(1);

    mtxStart($owner, DB::table('bookings')->where('guid', $booking->guid)->first(), 'second', 'qris', 'qris')->assertOk();
    expect(DB::table('payment_attempts')->count())->toBe(2)
        ->and(DB::table('payment_attempts')->where('attempt_number', 2)->value('instruction_expires_at'))->toBe($deadline)
        ->and(DB::table('bookings')->where('guid', $booking->guid)->value('payment_expires_at'))->toBe($deadline);
});

it('refuses the cancel and confirms the booking when the payment arrived in the same instant', function () {
    [$owner, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, 'pending');
    pcxFakeCancel(['status_code' => '412', 'status_message' => 'Transaction status cannot be updated.']);
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement', ['settlement_time' => mtxWib(now())]));

    pcxCancel($owner, $booking, $attempt->guid)->assertConflict()->assertJsonPath('code', 'payment_already_received');

    expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBe('confirmed')
        ->and(DB::table('payment_attempts')->where('guid', $attempt->guid)->value('status'))->toBe('paid');
});

it('keeps the attempt open and locks every new attempt while the provider has not proven the order closed', function () {
    [$owner, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, 'pending');
    pcxFakeCancel(['status_code' => '500']);
    mtxFakeStatus(mtxStatusBody($attempt, 'pending'));

    pcxCancel($owner, $booking, $attempt->guid)->assertConflict()->assertJsonPath('code', 'payment_verification_pending');
    $charge = mtxFakeCharge();
    mtxStart($owner, $booking, 'other', 'qris', 'qris')->assertOk()->assertJsonPath('data.attempt_guid', $attempt->guid);

    expect(DB::table('payment_attempts')->where('guid', $attempt->guid)->value('status'))->toBe('pending')
        ->and(DB::table('payment_attempts')->count())->toBe(1)->and($charge->calls)->toBe(0);
});

it('never asks the provider to cancel an attempt whose order is not proven', function (string $state) {
    [$owner, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, $state, 'virtual_account', 'bca', ['provider_reference' => null]);
    $cancel = pcxFakeCancel();

    pcxCancel($owner, $booking, $attempt->guid)->assertConflict()->assertJsonPath('code', 'payment_verification_pending');

    expect($cancel->calls)->toBe(0)->and(DB::table('payment_attempts')->where('guid', $attempt->guid)->value('status'))->toBe($state);
})->with(['initiating', 'unknown']);

it('hides another customer attempt and repeats a finished cancel harmlessly', function () {
    [$owner, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, 'failed', 'virtual_account', 'bca', ['finished_at' => now()]);
    $cancel = pcxFakeCancel();

    pcxCancel(makeUser(), $booking, $attempt->guid)->assertNotFound();
    pcxCancel($owner, $booking, $attempt->guid)->assertOk();

    expect($cancel->calls)->toBe(0);
});

it('cancels a booking that is awaiting payment and has no payment attempt, releasing its quota', function () {
    [$owner, $booking] = mtxPayable(startCountdown: false);
    expect(pcxHeld())->toBe(2);

    pcxBookingCancel($owner, $booking)->assertOk()->assertJsonPath('data.status', 'cancelled');

    expect(pcxHeld())->toBe(0)
        ->and(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'cancelled')->where('old_status', 'awaiting_payment')->count())->toBe(1)
        ->and(DB::table('notifications')->where('booking_guid', $booking->guid)->where('type', 'booking.cancelled')->exists())->toBeTrue();
});

it('cancels a booking after the provider proves its pending order closed, and cancels the intent', function () {
    [$owner, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, 'pending');
    $cancel = pcxFakeCancel();
    mtxFakeStatus(mtxStatusBody($attempt, 'cancel'));

    pcxBookingCancel($owner, $booking)->assertOk()->assertJsonPath('data.status', 'cancelled');

    expect($cancel->calls)->toBe(1)->and(pcxHeld())->toBe(0)
        ->and(DB::table('payment_intents')->value('status'))->toBe('cancelled')
        ->and(DB::table('payment_attempts')->where('guid', $attempt->guid)->value('status'))->toBe('failed');
});

it('refuses to cancel a booking that was paid in the meantime', function () {
    [$owner, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, 'pending');
    pcxFakeCancel(['status_code' => '412']);
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement', ['settlement_time' => mtxWib(now())]));

    pcxBookingCancel($owner, $booking)->assertConflict()->assertJsonPath('code', 'booking_already_paid');

    expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBe('confirmed')->and(pcxHeld())->toBe(2);
});

it('refuses to cancel a booking while an unproven attempt may still be paid', function () {
    [$owner, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, 'unknown', 'virtual_account', 'bca', ['provider_reference' => null]);
    pcxFakeCancel();

    pcxBookingCancel($owner, $booking)->assertConflict()->assertJsonPath('code', 'payment_verification_pending');

    expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBe('awaiting_payment')->and(pcxHeld())->toBe(2)
        ->and(DB::table('payment_intents')->value('status'))->not->toBe('cancelled');
});

it('exposes an awaiting-payment booking as cancellable and a confirmed one as not', function () {
    [$owner, $booking] = mtxPayable(startCountdown: false);

    $this->getJson('/api/v1/bookings/'.$booking->guid, bearer($owner))->assertOk()->assertJsonPath('data.cancellable', true);
    DB::table('bookings')->where('guid', $booking->guid)->update(['status' => 'confirmed']);
    $this->getJson('/api/v1/bookings/'.$booking->guid, bearer($owner))->assertOk()->assertJsonPath('data.cancellable', false);
});
