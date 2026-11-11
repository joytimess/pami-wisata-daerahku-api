<?php

use App\Jobs\ProcessMidtransRefund;
use App\Services\BookingRefundService;
use App\Services\BookingService;
use App\Services\PaymentSettlementService;
use App\Services\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/Support/MidtransFixtures.php';

uses(RefreshDatabase::class);

/*
 * Phase 4.1: deadline and legacy compatibility, the backend ticket state contract, refund worker lease ownership and transient
 * configuration failure before a refund is dispatched. Every provider answer is a fake.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 08:00:00', 'UTC'));
    Http::preventStrayRequests();
    Queue::fake();
});

function hardeningBookingTimes(object $booking): array
{
    $row = DB::table('bookings')->where('guid', $booking->guid)->first();
    $approved = mtxApprovedAt($booking);

    return [$approved, CarbonImmutable::parse($row->payment_expires_at, 'UTC'), CarbonImmutable::parse($row->notification_grace_expires_at, 'UTC')];
}

it('keeps the historical one hour approval window and grace when DOKU is the active gateway', function () {
    config(['operations.demo_payments_enabled' => false, 'midtrans.enabled' => false]);

    [, $booking] = mtxPayable();

    [$approved, $deadline, $grace] = hardeningBookingTimes($booking);
    expect($deadline->equalTo($approved->addSeconds(3600)))->toBeTrue()->and($grace->equalTo($approved->addSeconds(3600 + 900)))->toBeTrue();
});

it('keeps the historical one hour approval window and grace when DEMO is the active gateway', function () {
    config(['operations.demo_payments_enabled' => true, 'midtrans.enabled' => false]);

    [, $booking] = mtxPayable();

    [$approved, $deadline, $grace] = hardeningBookingTimes($booking);
    expect($deadline->equalTo($approved->addSeconds(3600)))->toBeTrue()->and($grace->equalTo($approved->addSeconds(3600 + 900)))->toBeTrue();
});

it('gives a Midtrans approval one absolute 900 second deadline with an internal 900 second grace', function () {
    mtxConfig();

    [, $booking] = mtxPayable();

    [$approved, $deadline, $grace] = hardeningBookingTimes($booking);
    expect($deadline->equalTo($approved->addSeconds(900)))->toBeTrue()->and($grace->equalTo($approved->addSeconds(1800)))->toBeTrue();
});

it('never lets the internal grace extend can_pay, can_create, Charge or retry', function () {
    mtxConfig();
    [$owner, $booking] = mtxPayable();
    mtxAttempt($booking, 'unknown', 'virtual_account', 'bca', ['provider_reference' => null, 'last_error_code' => 'provider_order_not_found']);
    [, $deadline, $grace] = hardeningBookingTimes($booking);

    foreach ([$deadline->addSecond(), $deadline->addMinutes(7), $grace->subSecond(), $grace->addSecond()] as $moment) {
        $this->travelTo($moment);
        app(BookingService::class)->expireDue();
        $intent = $this->getJson('/api/v1/bookings/'.$booking->guid.'/payment-intent', bearer($owner))->assertOk();
        expect($intent->json('data.can_pay'))->toBeFalse()->and($intent->json('data.can_create'))->toBeFalse()->and($intent->json('data.can_switch'))->toBeFalse();
        mtxStart($owner, $booking, 'late-'.$moment->timestamp)->assertOk()->assertJsonPath('data.status', 'unknown')
            ->assertJsonPath('data.instruction.presentation_type', 'unavailable');
    }

    Http::assertNotSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/v2/charge'));
    expect(DB::table('payment_attempts')->count())->toBe(1)->and(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBe('payment_review');
});

it('moves a Midtrans booking to review only after the grace, never earlier and never by the customer deadline alone', function () {
    mtxConfig();
    [, $booking] = mtxPayable();
    mtxAttempt($booking, 'unknown', 'virtual_account', 'bca', ['provider_reference' => null]);
    [, $deadline, $grace] = hardeningBookingTimes($booking);

    $this->travelTo($deadline->subSecond());
    app(BookingService::class)->expireDue();
    expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBe('awaiting_payment');
    $this->travelTo($deadline);
    app(BookingService::class)->expireDue();
    expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBe('payment_verification');
    $this->travelTo($grace->subSecond());
    app(BookingService::class)->expireDue();
    expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBe('payment_verification');
    $this->travelTo($grace);
    app(BookingService::class)->expireDue();
    expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBe('payment_review');
});

/** A QRIS booking paid and on its visit day with entry QR units issued: the starting point of the ticket state contract. */
function hardeningIssuedTickets(): array
{
    mtxConfig(['refund' => ['qris_api_enabled' => true, 'qris_window_days' => 14]]);
    [$owner, $booking, $attempt, $destination] = mtxPaid('qris', 'qris');
    $payload = mtxVisitDay($booking, $destination);

    return [$owner, $booking, $attempt, $destination, $payload];
}

function hardeningTickets(object $owner, object $booking)
{
    return test()->getJson('/api/v1/bookings/'.$booking->guid.'/tickets', bearer($owner))->assertOk();
}

it('reports an active entry QR explicitly and keeps every existing ticket field', function () {
    [$owner, $booking] = hardeningIssuedTickets();

    $response = hardeningTickets($owner, $booking);

    expect($response->json('data.ticket_state'))->toBe('available')->and($response->json('data.entitlement'))->toBe('paid')
        ->and($response->json('data.admission_state'))->toBe('active')->and($response->json('data.admission_reason'))->toBeNull()
        ->and($response->json('data.refund_pending'))->toBeFalse()->and($response->json('data.tickets.0.qr_available'))->toBeTrue()
        ->and($response->json('data.tickets.0.status'))->toBe('not_checked_in')->and($response->json('data.tickets.0.admission_state'))->toBe('active');
});

it('reports a claimed refund as a temporary admission block and withholds the QR, then a verified refund as permanent revocation', function () {
    [$owner, $booking, $attempt] = hardeningIssuedTickets();
    $refund = mtxRefundAsk($owner, $booking);
    mtxRefundDecide(makeUser(2), $booking, $refund)->assertOk()->assertJsonPath('data.status', 'refund_pending');

    $blocked = hardeningTickets($owner, $booking);

    expect($blocked->json('data.admission_state'))->toBe('temporarily_blocked')->and($blocked->json('data.admission_reason'))->toBe('refund_in_progress')
        ->and($blocked->json('data.refund_pending'))->toBeTrue()->and($blocked->json('data.ticket_state'))->toBe('available')
        ->and($blocked->json('data.tickets.0.qr_available'))->toBeFalse()->and($blocked->json('data.tickets.0.status'))->toBe('not_checked_in')
        ->and($blocked->json('data.tickets.0.admission_state'))->toBe('temporarily_blocked');
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/payment-intent', bearer($owner))->assertOk()
        ->assertJsonPath('data.refund_request.status', 'refund_pending')->assertJsonPath('data.refund_request.admission_blocked', true);

    mtxFakeRefund(mtxRefundAccepted($attempt));
    mtxRefundRowStep($refund);
    mtxFakeStatus(mtxRefundStatus($attempt, 'PAMI-RF-'.$refund));
    mtxRefundRowStep($refund);
    mtxRefundRowStep($refund);

    $revoked = hardeningTickets($owner, $booking);
    expect($revoked->json('data.admission_state'))->toBe('revoked')->and($revoked->json('data.admission_reason'))->toBe('refunded')
        ->and($revoked->json('data.refund_pending'))->toBeFalse()->and($revoked->json('data.ticket_state'))->toBe('revoked')
        ->and($revoked->json('data.tickets.0.qr_available'))->toBeFalse()->and($revoked->json('data.tickets.0.status'))->toBe('revoked');
});

it('reports a payment under review as a temporary block, and a fully checked in booking as used', function () {
    [$owner, $booking, $attempt, , $payload] = hardeningIssuedTickets();

    app(PaymentSettlementService::class)->flagAmbiguousProviderOutcome($attempt->guid, 'partial_adjustment_review');
    $review = hardeningTickets($owner, $booking);
    expect($review->json('data.admission_state'))->toBe('temporarily_blocked')->and($review->json('data.admission_reason'))->toBe('payment_under_review')
        ->and($review->json('data.tickets.0.qr_available'))->toBeFalse();

    [$owner2, $booking2, , , $payload2] = hardeningIssuedTickets();
    mtxCheckIn($payload2)->assertOk();
    $units = DB::table('booking_tickets')->where('booking_guid', $booking2->guid)->orderBy('sequence')->get();
    foreach ($units as $unit) {
        DB::table('booking_tickets')->where('guid', $unit->guid)->update(['checked_in_at' => now()]);
    }
    expect(hardeningTickets($owner2, $booking2)->json('data.admission_state'))->toBe('used');
});

it('reports a booking that is not admissible yet as unavailable, not as blocked', function () {
    mtxConfig();
    [$owner, $booking] = mtxPayable();
    app(TicketService::class)->issue($booking->guid);

    $response = hardeningTickets($owner, $booking);

    expect($response->json('data.admission_state'))->toBe('unavailable')->and($response->json('data.admission_reason'))->toBe('awaiting_payment');
});

function mtxRefundRowStep(string $refund): string
{
    return app(BookingRefundService::class)->processProviderRefund($refund);
}

/** A QRIS booking paid, refund asked and approved while the provider API is enabled. @return array<int, mixed> */
function hardeningClaimedRefund(): array
{
    mtxConfig(['refund' => ['qris_api_enabled' => true, 'qris_window_days' => 14]]);
    [$owner, $booking, $attempt, $destination] = mtxPaid('qris', 'qris');
    $refund = mtxRefundAsk($owner, $booking);
    mtxRefundDecide(makeUser(2), $booking, $refund)->assertOk()->assertJsonPath('data.status', 'refund_pending');

    return [$owner, $booking, $attempt, $destination, $refund];
}

it('lets a worker release only its own refund claim, never the claim of the worker that replaced it', function () {
    [, , $attempt, , $refund] = hardeningClaimedRefund();
    $stolen = null;
    mtxFakeRefund(function (ClientRequest $request) use ($refund, $attempt, &$stolen) {
        // The first worker is slow: its lease expired and a second worker claimed the same request while this call was in flight.
        $stolen = now()->addSeconds(120);
        DB::table('booking_refund_requests')->where('guid', $refund)->update(['dispatch_claimed_until' => $stolen, 'dispatch_claim_token' => 'new-worker-token']);

        return mtxRefundAccepted($attempt)($request);
    });

    mtxRefundRowStep($refund);

    $row = mtxRefundRow($refund);
    expect($row->dispatch_claim_token)->toBe('new-worker-token')->and($row->dispatch_claimed_until)->not->toBeNull();
});

it('clears its own refund claim when it finishes and lets the next pass claim again', function () {
    [, , $attempt, , $refund] = hardeningClaimedRefund();
    mtxFakeRefund(mtxRefundAccepted($attempt));

    expect(mtxRefundRowStep($refund))->toBe('sent');

    $row = mtxRefundRow($refund);
    expect($row->dispatch_claim_token)->toBeNull()->and($row->dispatch_claimed_until)->toBeNull();
});

it('does not record a provider answer for a claim it no longer owns', function () {
    [, , $attempt, , $refund] = hardeningClaimedRefund();
    mtxFakeRefund(function (ClientRequest $request) use ($refund, $attempt) {
        DB::table('booking_refund_requests')->where('guid', $refund)->update(['dispatch_claimed_until' => now()->addSeconds(120), 'dispatch_claim_token' => 'new-worker-token']);

        return mtxRefundAccepted($attempt)($request);
    });

    mtxRefundRowStep($refund);

    $row = mtxRefundRow($refund);
    expect($row->provider_refund_reference)->toBeNull()->and($row->status)->toBe('refund_pending')->and((int) $row->dispatch_attempts)->toBe(1);
});

it('keeps a claimed refund on the provider route with its hold when configuration fails transiently before the first send', function () {
    [, , $attempt, , $refund] = hardeningClaimedRefund();
    $serverKey = config('midtrans.server_key');
    config(['midtrans.server_key' => '']);
    mtxFakeRefund(mtxRefundAccepted($attempt));

    expect(mtxRefundRowStep($refund))->toBe('deferred');

    $row = mtxRefundRow($refund);
    expect($row->status)->toBe('refund_pending')->and($row->refund_route)->toBe('provider_api')->and((int) $row->admission_hold)->toBe(1)
        ->and($row->dispatch_started_at)->toBeNull()->and($row->last_error_code)->toBe('midtrans_not_configured')
        ->and($row->refund_key)->toBe('PAMI-RF-'.$refund)->and(CarbonImmutable::parse($row->next_reconcile_at, 'UTC')->gt(now()))->toBeTrue();
    Http::assertNotSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/refund'));

    config(['midtrans.server_key' => $serverKey]);
    $this->travelTo(now()->addMinutes(20));
    expect(mtxRefundRowStep($refund))->toBe('sent');
    $row = mtxRefundRow($refund);
    expect($row->refund_route)->toBe('provider_api')->and($row->refund_key)->toBe('PAMI-RF-'.$refund)->and($row->dispatch_started_at)->not->toBeNull();
    Http::assertSentCount(1);
});

it('escalates a refund whose configuration never recovers to review with the key and hold kept, not to the manual route', function () {
    [, , , , $refund] = hardeningClaimedRefund();
    config(['midtrans.server_key' => '']);

    for ($round = 0; $round < 14; $round++) {
        $this->travelTo(now()->addMinutes(20));
        mtxRefundRowStep($refund);
    }

    $row = mtxRefundRow($refund);
    expect($row->status)->toBe('needs_review')->and($row->review_reason)->toBe('refund_configuration_unavailable')
        ->and($row->refund_route)->toBe('provider_api')->and((int) $row->admission_hold)->toBe(1)->and($row->dispatch_started_at)->toBeNull()
        ->and($row->refund_key)->toBe('PAMI-RF-'.$refund)->and($row->escalated_at)->not->toBeNull();
});

it('still sends a permanently unsupported refund to the manual route before dispatch', function () {
    [, , , , $refund] = hardeningClaimedRefund();
    config(['midtrans.refund.qris_window_days' => 1]);
    $this->travelTo(now()->addDays(2));

    expect(mtxRefundRowStep($refund))->toBe('released');

    $row = mtxRefundRow($refund);
    expect($row->status)->toBe('manual_action_required')->and($row->refund_route)->toBe('manual')->and($row->admission_hold)->toBeNull();
});

it('claims the provider route at the decision even if configuration blips at that instant, and sends once it recovers', function () {
    mtxConfig(['refund' => ['qris_api_enabled' => true, 'qris_window_days' => 14]]);
    [$owner, $booking, $attempt] = mtxPaid('qris', 'qris');
    $refund = mtxRefundAsk($owner, $booking);
    $serverKey = config('midtrans.server_key');
    config(['midtrans.server_key' => '']);

    mtxRefundDecide(makeUser(2), $booking, $refund)->assertOk()->assertJsonPath('data.status', 'refund_pending');

    expect(mtxRefundRow($refund)->refund_route)->toBe('provider_api');
    config(['midtrans.server_key' => $serverKey]);
    mtxFakeRefund(mtxRefundAccepted($attempt));
    expect(mtxRefundRowStep($refund))->toBe('sent');
});

it('keeps a VA refund on the manual route even if configuration blips at the decision', function () {
    mtxConfig(['refund' => ['qris_api_enabled' => true, 'qris_window_days' => 14]]);
    [$owner, $booking] = mtxPaid('virtual_account', 'bca');
    $refund = mtxRefundAsk($owner, $booking);
    config(['midtrans.server_key' => '']);

    mtxRefundDecide(makeUser(2), $booking, $refund)->assertOk()->assertJsonPath('data.status', 'manual_action_required');

    expect(mtxRefundRow($refund)->refund_route)->toBe('manual');
    Queue::assertNotPushed(ProcessMidtransRefund::class);
});
