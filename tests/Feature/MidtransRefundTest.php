<?php

use App\Jobs\ProcessMidtransRefund;
use App\Models\Destination;
use App\Services\BookingRefundService;
use App\Services\MidtransPaymentService;
use App\Services\PaymentEligibility;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/Support/MidtransFixtures.php';

uses(RefreshDatabase::class);

/*
 * Phase 4 D-F: full-only refunds (manual VA, conditional QRIS API), refund versus check-in, verified revocation and recovery.
 * Every provider answer is a fake; nothing here reaches a real host.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 08:00:00', 'UTC'));
    mtxConfig(['refund' => ['qris_api_enabled' => true, 'qris_window_days' => 14]]);
    Http::preventStrayRequests();
    Queue::fake();
});

/** A QRIS booking paid, refund asked and approved: the request is claimed for the provider API. @return array<int, mixed> */
function mtxClaimedQrisRefund(): array
{
    [$owner, $booking, $attempt, $destination] = mtxPaid('qris', 'qris');
    $refund = mtxRefundAsk($owner, $booking);
    mtxRefundDecide(makeUser(2), $booking, $refund)->assertOk()->assertJsonPath('data.status', 'refund_pending');

    return [$owner, $booking, $attempt, $destination, $refund];
}

function mtxAssertNoRefundCall(): void
{
    Http::assertNotSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/refund'));
}

function mtxRefundStep(string $refund): string
{
    return app(BookingRefundService::class)->processProviderRefund($refund);
}

function mtxEntitlement(object $booking): string
{
    return app(PaymentEligibility::class)->entitlement(DB::table('bookings')->where('guid', $booking->guid)->first());
}

function mtxUnusedQrUnits(object $booking): int
{
    return DB::table('booking_tickets')->where('booking_guid', $booking->guid)->whereNotNull('qr_token_hash')->whereNull('qr_revoked_at')->whereNull('checked_in_at')->count();
}

it('lets the owner of a Midtrans-paid booking ask for the full amount only', function () {
    [$owner, $booking] = mtxPaid('virtual_account', 'bni');
    $path = '/api/v1/bookings/'.$booking->guid.'/refund-requests';

    $this->postJson($path, ['reason' => 'Perubahan rencana perjalanan', 'amount' => 1000], bearer($owner) + ['Idempotency-Key' => 'partial'])->assertUnprocessable();
    $created = $this->postJson($path, ['reason' => 'Perubahan rencana perjalanan'], bearer($owner) + ['Idempotency-Key' => 'full'])->assertOk();

    expect($created->json('data.amount'))->toBe(20000)->and($created->json('data.status'))->toBe('requested')
        ->and(DB::table('booking_refund_requests')->count())->toBe(1);
});

it('sends BCA and BNI virtual accounts to the manual workflow and never calls the Refund API', function (string $channel) {
    [$owner, $booking, $attempt, $destination] = mtxPaid('virtual_account', $channel);
    $refund = mtxRefundAsk($owner, $booking);

    mtxRefundDecide(makeUser(2), $booking, $refund)->assertOk()->assertJsonPath('data.status', 'manual_action_required');

    $row = mtxRefundRow($refund);
    expect($row->refund_route)->toBe('manual')->and($row->refund_key)->toBeNull()->and($row->admission_hold)->toBeNull();
    Queue::assertNotPushed(ProcessMidtransRefund::class);
    mtxAssertNoRefundCall();
    expect(DB::table('booking_refund_request_events')->where('refund_request_guid', $refund)->where('note', 'like', 'provider_refund_unavailable:refund_api_unsupported_channel')->exists())->toBeTrue();
})->with(['bca', 'bni']);

it('settles a VA refund manually only with scoped authority, a unique reference and verified evidence', function () {
    [$owner, $booking, $attempt, $destination] = mtxPaid('virtual_account', 'bca');
    $refund = mtxRefundAsk($owner, $booking);
    $admin = makeUser(1);
    assignTestDestination($admin, $destination);
    $outsider = makeUser(1);
    $body = ['settlement_method' => 'bank_transfer', 'settlement_reference' => 'TRF-2026-110801', 'settlement_amount' => 20000,
        'settled_at' => now()->toIso8601String(), 'evidence_note' => 'Bukti transfer BCA diperiksa pengelola', 'proof_verified' => true];
    $path = '/api/v1/admin/bookings/'.$booking->guid.'/refund-requests/'.$refund;

    $this->postJson($path.'/decision', ['decision' => 'approve'], bearer($outsider))->assertStatus(404);
    mtxRefundDecide($admin, $booking, $refund)->assertOk()->assertJsonPath('data.status', 'manual_action_required');
    expect(mtxEntitlement($booking))->toBe('paid');
    $this->postJson($path.'/manual-settlement', $body, bearer($outsider))->assertStatus(404);
    $this->postJson($path.'/manual-settlement', array_replace($body, ['settlement_amount' => 1000]), bearer($admin))->assertConflict()->assertJsonPath('code', 'refund_amount_mismatch');
    $this->postJson($path.'/manual-settlement', $body, bearer($admin))->assertOk()->assertJsonPath('data.status', 'manual_refunded');

    $row = mtxRefundRow($refund);
    expect($row->settled_by_user_guid)->toBe($admin->guid)->and($row->settlement_reference)->toBe('TRF-2026-110801')->and((int) $row->settlement_amount)->toBe(20000)
        ->and(mtxEntitlement($booking))->toBe('refunded')->and(mtxUnusedQrUnits($booking))->toBe(0);
    mtxAssertNoRefundCall();
});

it('falls back to the manual workflow before dispatch when the QRIS refund capability is not available', function (array $config, string $reason) {
    mtxConfig(['refund' => $config]);
    [$owner, $booking] = mtxPaid('qris', 'qris');
    $refund = mtxRefundAsk($owner, $booking);

    mtxRefundDecide(makeUser(2), $booking, $refund)->assertOk()->assertJsonPath('data.status', 'manual_action_required');

    expect(mtxRefundRow($refund)->refund_key)->toBeNull()->and(mtxRefundRow($refund)->admission_hold)->toBeNull();
    expect(DB::table('booking_refund_request_events')->where('refund_request_guid', $refund)->where('note', 'provider_refund_unavailable:'.$reason)->exists())->toBeTrue();
    Queue::assertNotPushed(ProcessMidtransRefund::class);
    mtxAssertNoRefundCall();
})->with([
    'capability off' => [['qris_api_enabled' => false, 'qris_window_days' => 14], 'refund_api_not_enabled'],
    'window closed' => [['qris_api_enabled' => true, 'qris_window_days' => 0], 'refund_window_closed'],
]);

it('claims a QRIS refund durably with its key and an admission hold before any provider call', function () {
    [, $booking, $attempt, , $refund] = mtxClaimedQrisRefund();
    $row = mtxRefundRow($refund);

    expect($row->status)->toBe('refund_pending')->and($row->refund_route)->toBe('provider_api')->and($row->refund_key)->toBe('PAMI-RF-'.$refund)
        ->and((int) $row->admission_hold)->toBe(1)->and($row->dispatch_started_at)->toBeNull()->and($row->active)->toBe(1);
    Queue::assertPushed(ProcessMidtransRefund::class, fn (ProcessMidtransRefund $job) => $job->refundGuid === $refund);
    mtxAssertNoRefundCall();
    expect(mtxEntitlement($booking))->toBe('paid');
});

it('dispatches the full amount with the persisted key, and an accepted answer is not proof of returned funds', function () {
    [, $booking, $attempt, , $refund] = mtxClaimedQrisRefund();
    $seen = null;
    $calls = mtxFakeRefund(function (ClientRequest $request) use ($refund, $attempt, &$seen) {
        $seen = mtxRefundRow($refund);

        return (mtxRefundAccepted($attempt))($request);
    });

    mtxRefundStep($refund);

    $row = mtxRefundRow($refund);
    expect($calls->calls)->toBe(1)->and($calls->urls[0])->toBe('https://api.sandbox.midtrans.com/v2/'.$attempt->merchant_reference.'/refund')
        ->and($calls->payloads[0]['refund_key'])->toBe('PAMI-RF-'.$refund)->and($calls->payloads[0]['amount'])->toBe(20000)
        ->and($seen->dispatch_started_at)->not->toBeNull()->and($seen->refund_key)->toBe('PAMI-RF-'.$refund)->and((int) $seen->admission_hold)->toBe(1)
        ->and($row->status)->toBe('refund_pending')->and($row->provider_refund_reference)->toBe('7')->and((int) $row->admission_hold)->toBe(1)
        ->and(mtxEntitlement($booking))->toBe('paid')->and(DB::table('payment_attempts')->where('guid', $attempt->guid)->value('status'))->toBe('paid');
    expect(json_encode($calls->payloads))->not->toContain('SB-Mid-server');
});

it('applies a verified full refund once: attempt reversed, entitlement and QR revoked for good, hold cleared', function () {
    [, $booking, $attempt, $destination, $refund] = mtxClaimedQrisRefund();
    $payload = mtxVisitDayPayloadBeforeRefund($booking, $destination);
    mtxFakeRefund(mtxRefundAccepted($attempt));
    mtxRefundStep($refund);
    mtxFakeStatus(mtxRefundStatus($attempt, 'PAMI-RF-'.$refund));

    mtxRefundStep($refund);
    mtxRefundStep($refund);

    $row = mtxRefundRow($refund);
    expect($row->status)->toBe('refunded')->and($row->active)->toBeNull()->and($row->admission_hold)->toBeNull()->and($row->refund_channel)->toBeNull()
        ->and(DB::table('payment_attempts')->where('guid', $attempt->guid)->value('status'))->toBe('reversed')
        ->and(mtxEntitlement($booking))->toBe('refunded')->and(mtxUnusedQrUnits($booking))->toBe(0)
        ->and(DB::table('payment_events')->where('event_type', 'refund')->count())->toBe(1);
    mtxCheckIn($payload)->assertConflict()->assertJsonPath('code', 'ticket_revoked');

    // A stale or replayed settlement can never bring the ticket back.
    $this->travelTo(now()->addMinutes(2));
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement'));
    $this->postJson('/api/v1/webhooks/midtrans', mtxNotification($attempt, 'settlement'))->assertOk();
    mtxDrain();
    expect(mtxEntitlement($booking))->toBe('refunded')->and(mtxUnusedQrUnits($booking))->toBe(0)->and(DB::table('booking_refund_requests')->value('status'))->toBe('refunded');
    mtxCheckIn($payload)->assertConflict();
});

/** Issues the entry QR while the booking is still valid and returns the scanner payload; the clock stays on the visit day. */
function mtxVisitDayPayloadBeforeRefund(object $booking, Destination $destination): string
{
    return mtxVisitDay($booking, $destination);
}

it('keeps the refund pending and the hold on a timeout, then resends the SAME key and never starts a manual refund', function () {
    [, $booking, $attempt, , $refund] = mtxClaimedQrisRefund();
    $calls = mtxFakeRefund(new ConnectionException('timeout'));
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement'));

    mtxRefundStep($refund);

    $row = mtxRefundRow($refund);
    expect($row->status)->toBe('refund_pending')->and((int) $row->admission_hold)->toBe(1)->and($row->dispatch_started_at)->not->toBeNull()
        ->and($row->last_error_code)->toBe('provider_unavailable')->and($row->dispatch_claimed_until)->toBeNull()
        ->and(DB::table('booking_refund_requests')->where('status', 'manual_action_required')->count())->toBe(0);

    mtxFakeRefund(mtxRefundAccepted($attempt));
    mtxRefundStep($refund);

    $keys = collect($calls->payloads)->pluck('refund_key')->unique()->all();
    expect($calls->calls)->toBe(2)->and($keys)->toBe(['PAMI-RF-'.$refund])->and(mtxRefundRow($refund)->provider_refund_reference)->toBe('7')
        ->and(mtxRefundRow($refund)->refund_key)->toBe('PAMI-RF-'.$refund);
});

it('recovers a worker crash with the same key and never re-sends an accepted refund', function () {
    [, , $attempt, , $refund] = mtxClaimedQrisRefund();
    // The worker died right after it recorded the dispatch start: the provider may or may not have the request.
    DB::table('booking_refund_requests')->where('guid', $refund)->update(['dispatch_started_at' => now(), 'dispatch_attempts' => 1, 'dispatch_claimed_until' => now()->subSecond()]);
    $calls = mtxFakeRefund(mtxRefundAccepted($attempt));
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement'));

    mtxRefundStep($refund);
    mtxRefundStep($refund);
    mtxRefundStep($refund);

    expect($calls->calls)->toBe(1)->and($calls->payloads[0]['refund_key'])->toBe('PAMI-RF-'.$refund)
        ->and(mtxRefundRow($refund)->provider_refund_reference)->toBe('7');
});

it('does not run a refund twice while another worker holds the lease', function () {
    [, , $attempt, , $refund] = mtxClaimedQrisRefund();
    DB::table('booking_refund_requests')->where('guid', $refund)->update(['dispatch_claimed_until' => now()->addMinute()]);
    $calls = mtxFakeRefund(mtxRefundAccepted($attempt));

    expect(mtxRefundStep($refund))->toBe('skipped')->and($calls->calls)->toBe(0);
});

it('releases a definitively rejected first dispatch to the manual workflow and the hold with it', function () {
    [$owner, $booking, $attempt, $destination, $refund] = mtxClaimedQrisRefund();
    $payload = mtxVisitDay($booking, $destination);
    // The refusal is explicit AND the Status API still shows an unrefunded settlement: only then is the money path abandoned.
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement'));
    mtxFakeRefund(['status_code' => '400', 'status_message' => 'Refund request is invalid.']);

    mtxRefundStep($refund);

    $row = mtxRefundRow($refund);
    expect($row->status)->toBe('manual_action_required')->and($row->refund_route)->toBe('manual')->and($row->admission_hold)->toBeNull()
        ->and($row->review_reason)->toBe('provider_refund_rejected')->and($row->refund_key)->toBe('PAMI-RF-'.$refund)->and($row->active)->toBe(1)
        ->and(mtxEntitlement($booking))->toBe('paid');
    mtxCheckIn($payload)->assertOk();
});

it('does not treat an ambiguous provider answer (412, 404, 409, redirect) as a refusal and never opens the manual route', function (int|string $httpStatus, ?string $bodyCode) {
    [, , $attempt, , $refund] = mtxClaimedQrisRefund();
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement'));
    mtxFakeRefund($bodyCode ? ['status_code' => $bodyCode, 'status_message' => 'ambiguous'] : ['status_message' => 'ambiguous'], (int) $httpStatus);

    mtxRefundStep($refund);

    $row = mtxRefundRow($refund);
    expect($row->status)->toBe('refund_pending')->and($row->refund_route)->toBe('provider_api')->and((int) $row->admission_hold)->toBe(1)
        ->and($row->refund_key)->toBe('PAMI-RF-'.$refund);
})->with([
    'body 412' => [200, '412'], 'body 404' => [200, '404'], 'http 409' => [409, null], 'http 408' => [408, null], 'http 302' => [302, null],
]);

it('settles through the verified Status API instead of opening the manual route when a refused refund already exists at the provider', function () {
    [, , $attempt, , $refund] = mtxClaimedQrisRefund();
    $this->travelTo(now()->addSeconds(30));
    mtxFakeStatus(mtxRefundStatus($attempt, 'PAMI-RF-'.$refund));
    mtxFakeRefund(['status_code' => '400', 'status_message' => 'Refund request is invalid.']);

    mtxRefundStep($refund);

    $row = mtxRefundRow($refund);
    expect($row->status)->toBe('refunded')->and($row->refund_route)->toBe('provider_api')->and($row->admission_hold)->toBeNull();
});

it('keeps a refused refund on the provider route with the hold while the Status API cannot confirm that nothing was refunded', function () {
    [, , $attempt, , $refund] = mtxClaimedQrisRefund();
    mtxFakeStatus(['status_code' => '500'], 500);
    mtxFakeRefund(['status_code' => '400', 'status_message' => 'Refund request is invalid.']);

    mtxRefundStep($refund);

    $row = mtxRefundRow($refund);
    expect($row->status)->toBe('refund_pending')->and($row->refund_route)->toBe('provider_api')->and((int) $row->admission_hold)->toBe(1)
        ->and($row->last_error_code)->toBe('provider_refund_rejected_unverified')->and($row->refund_key)->toBe('PAMI-RF-'.$refund);
});

it('sends a claim to review instead of the provider when the locked payment rows no longer show one settled payment', function () {
    [, , $attempt, , $refund] = mtxClaimedQrisRefund();
    DB::table('payment_intents')->update(['status' => 'needs_review']);
    $calls = mtxFakeRefund(mtxRefundAccepted($attempt));

    mtxRefundStep($refund);

    $row = mtxRefundRow($refund);
    expect($row->status)->toBe('needs_review')->and((int) $row->admission_hold)->toBe(1)->and($row->review_reason)->toBe('payment_state_changed')
        ->and($calls->calls)->toBe(0);
});

it('sends a rejection that follows an ambiguous dispatch to review and keeps the hold', function () {
    [, $booking, $attempt, , $refund] = mtxClaimedQrisRefund();
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement'));
    mtxFakeRefund(new ConnectionException('timeout'));
    mtxRefundStep($refund);
    mtxFakeRefund(['status_code' => '400', 'status_message' => 'Refund request is invalid.']);

    mtxRefundStep($refund);

    $row = mtxRefundRow($refund);
    expect($row->status)->toBe('needs_review')->and((int) $row->admission_hold)->toBe(1)->and($row->review_reason)->toBe('provider_refund_rejected');
});

it('quarantines a provider answer that is not the full refund we asked for', function () {
    [, $booking, $attempt, , $refund] = mtxClaimedQrisRefund();
    mtxFakeRefund(mtxRefundAccepted($attempt, ['refund_amount' => '1000.00']));

    mtxRefundStep($refund);

    $row = mtxRefundRow($refund);
    expect($row->status)->toBe('needs_review')->and((int) $row->admission_hold)->toBe(1)->and($row->review_reason)->toBe('partial_adjustment_review')
        ->and(DB::table('payment_intents')->value('status'))->toBe('needs_review')->and(mtxEntitlement($booking))->not->toBe('refunded');
});

it('quarantines an unexpected partial adjustment seen by the Status API and keeps the hold', function () {
    [, $booking, $attempt, , $refund] = mtxClaimedQrisRefund();
    mtxFakeRefund(mtxRefundAccepted($attempt));
    mtxRefundStep($refund);
    $this->travelTo(now()->addMinute());
    mtxFakeStatus(mtxStatusBody($attempt, 'partial_refund'));

    mtxRefundStep($refund);

    $row = mtxRefundRow($refund);
    expect($row->status)->toBe('needs_review')->and((int) $row->admission_hold)->toBe(1)
        ->and(DB::table('payment_intents')->value('status'))->toBe('needs_review')->and(mtxEntitlement($booking))->toBe('unverified')
        ->and(DB::table('payment_events')->where('event_type', 'refund')->count())->toBe(0);
});

it('blocks check-in while a claimed refund is unresolved, including after an ambiguous dispatch', function () {
    [, $booking, $attempt, $destination, $refund] = mtxClaimedQrisRefund();
    $payload = mtxVisitDay($booking, $destination);

    mtxCheckIn($payload)->assertConflict()->assertJsonPath('code', 'refund_in_progress');
    expect(DB::table('booking_tickets')->whereNotNull('checked_in_at')->count())->toBe(0);

    mtxFakeRefund(new ConnectionException('timeout'));
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement'));
    mtxRefundStep($refund);
    mtxCheckIn($payload)->assertConflict()->assertJsonPath('code', 'refund_in_progress');
});

it('refuses the automatic refund when a ticket was already used, and sends it to authorized review', function () {
    [$owner, $booking, $attempt, $destination] = mtxPaid('qris', 'qris');
    $refund = mtxRefundAsk($owner, $booking);
    mtxCheckIn(mtxVisitDay($booking, $destination))->assertOk();

    mtxRefundDecide(makeUser(2), $booking, $refund)->assertOk()->assertJsonPath('data.status', 'needs_review');

    $row = mtxRefundRow($refund);
    expect($row->refund_key)->toBeNull()->and($row->admission_hold)->toBeNull()->and($row->review_reason)->toBe('usage_or_payment_changed');
    Queue::assertNotPushed(ProcessMidtransRefund::class);
    mtxAssertNoRefundCall();
});

it('never lets a ticket checked in after the claim slip through the dispatch step', function () {
    [, $booking, $attempt, $destination, $refund] = mtxClaimedQrisRefund();
    // A used unit that appeared despite the hold (a defect elsewhere) must still stop the money from moving.
    $ticket = DB::table('booking_tickets')->where('booking_guid', $booking->guid)->orderBy('sequence')->first();
    DB::table('booking_tickets')->where('guid', $ticket->guid)->update(['checked_in_at' => now()]);
    $calls = mtxFakeRefund(mtxRefundAccepted($attempt));

    mtxRefundStep($refund);

    expect($calls->calls)->toBe(0)->and(mtxRefundRow($refund)->status)->toBe('needs_review')->and(mtxRefundRow($refund)->review_reason)->toBe('used_admission');
});

it('stops dispatch and falls back to manual if the capability is switched off before the first send', function () {
    [, , $attempt, , $refund] = mtxClaimedQrisRefund();
    mtxConfig(['refund' => ['qris_api_enabled' => false]]);
    $calls = mtxFakeRefund(mtxRefundAccepted($attempt));

    mtxRefundStep($refund);

    $row = mtxRefundRow($refund);
    expect($calls->calls)->toBe(0)->and($row->status)->toBe('manual_action_required')->and($row->admission_hold)->toBeNull();
});

it('does not allow a manual settlement while the provider refund is unresolved', function () {
    [, $booking, , , $refund] = mtxClaimedQrisRefund();

    test()->postJson('/api/v1/admin/bookings/'.$booking->guid.'/refund-requests/'.$refund.'/manual-settlement', [
        'settlement_method' => 'bank_transfer', 'settlement_reference' => 'TRF-2026-110802', 'settlement_amount' => 20000,
        'settled_at' => now()->toIso8601String(), 'evidence_note' => 'Bukti transfer diperiksa pengelola', 'proof_verified' => true,
    ], bearer(makeUser(2)))->assertConflict()->assertJsonPath('code', 'refund_not_settleable');
});

it('records a provider refund after a manual settlement as a duplicate to reclaim, not as a second refund', function () {
    [$owner, $booking, $attempt] = mtxPaid('virtual_account', 'bni');
    $refund = mtxRefundAsk($owner, $booking);
    $admin = makeUser(2);
    mtxRefundDecide($admin, $booking, $refund)->assertOk();
    test()->postJson('/api/v1/admin/bookings/'.$booking->guid.'/refund-requests/'.$refund.'/manual-settlement', [
        'settlement_method' => 'bank_transfer', 'settlement_reference' => 'TRF-2026-110803', 'settlement_amount' => 20000,
        'settled_at' => now()->toIso8601String(), 'evidence_note' => 'Bukti transfer diperiksa pengelola', 'proof_verified' => true,
    ], bearer($admin))->assertOk();
    $this->travelTo(now()->addMinute());
    mtxFakeStatus(mtxRefundStatus($attempt));
    $this->postJson('/api/v1/webhooks/midtrans', mtxNotification($attempt, 'refund'))->assertOk();
    mtxDrain();

    $row = mtxRefundRow($refund);
    expect($row->status)->toBe('manual_refunded')->and($row->review_reason)->toBe('duplicate_refund_detected')
        ->and(DB::table('booking_refund_requests')->count())->toBe(1);
});

it('escalates a refund that stays unverified after bounded reconciliation and keeps the hold', function () {
    [, , $attempt, , $refund] = mtxClaimedQrisRefund();
    mtxFakeRefund(mtxRefundAccepted($attempt));
    mtxRefundStep($refund);
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement'));
    DB::table('booking_refund_requests')->where('guid', $refund)->update(['reconcile_count' => 19]);

    mtxRefundStep($refund);

    $row = mtxRefundRow($refund);
    expect($row->status)->toBe('needs_review')->and($row->review_reason)->toBe('refund_unverified')->and($row->escalated_at)->not->toBeNull()
        ->and((int) $row->admission_hold)->toBe(1)->and($row->refund_key)->toBe('PAMI-RF-'.$refund);
});

it('lets the scheduler dispatch only due provider refunds, bounded by the limit', function () {
    [, , , , $due] = mtxClaimedQrisRefund();
    DB::table('booking_refund_requests')->where('guid', $due)->update(['next_reconcile_at' => now()->subSecond()]);
    [, , , , $later] = mtxClaimedQrisRefund();
    DB::table('booking_refund_requests')->where('guid', $later)->update(['next_reconcile_at' => now()->addMinutes(10)]);
    Queue::fake();

    expect(Artisan::call('payments:reconcile-midtrans'))->toBe(0);

    Queue::assertPushed(ProcessMidtransRefund::class, 1);
    Queue::assertPushed(ProcessMidtransRefund::class, fn (ProcessMidtransRefund $job) => $job->refundGuid === $due);
});

it('escalates a payment attempt whose Status answers stay invalid instead of retrying forever', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, 'pending');
    mtxFakeStatus(mtxStatusBody($attempt, 'pending', ['order_id' => 'SOMEONE-ELSE']));

    foreach (range(1, 6) as $round) {
        DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['next_reconcile_at' => now()->subSecond(), 'last_reconciled_at' => now()->subMinute()]);
        app(MidtransPaymentService::class)->reconcile($attempt->guid);
    }

    expect(DB::table('payment_intents')->value('status'))->toBe('needs_review')
        ->and(DB::table('payment_attempts')->where('guid', $attempt->guid)->value('status'))->toBe('pending')
        ->and(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBe('awaiting_payment')
        ->and(DB::table('payment_events')->where('event_type', 'capture')->count())->toBe(0);
});
