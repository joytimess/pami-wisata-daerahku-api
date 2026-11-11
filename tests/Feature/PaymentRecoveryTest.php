<?php

use App\Jobs\ProcessMidtransRefund;
use App\Models\Destination;
use App\Services\BookingRefundService;
use App\Services\BookingService;
use App\Services\MidtransPaymentService;
use App\Services\PaymentEligibility;
use App\Services\PaymentSettlementService;
use App\Support\BookingRules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/Support/MidtransFixtures.php';

uses(RefreshDatabase::class);

/*
 * Phase 4.1: authorized operator recovery for payments, unknown Charge outcomes and refunds. An operator never writes `paid`,
 * `refunded` or a terminal-unpaid outcome on an assumption: the server verifies the evidence itself, the operator's input only
 * corroborates it, every decision is audited, and insufficient evidence leaves the investigation state and the holds untouched.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 08:00:00', 'UTC'));
    mtxConfig(['refund' => ['qris_api_enabled' => true, 'qris_window_days' => 14]]);
    Http::preventStrayRequests();
    Queue::fake();
});

function rcvScopedAdmin(object $booking): object
{
    $destinationGuid = DB::table('destination_quotas')->where('guid', $booking->destination_quota_guid)->value('destination_guid');
    $admin = makeUser(1);
    assignTestDestination($admin, Destination::where('guid', $destinationGuid)->firstOrFail());

    return $admin;
}

function rcvPayment(object $actor, object $booking, array $body, ?string $key = null)
{
    return test()->postJson('/api/v1/admin/bookings/'.$booking->guid.'/payment-recovery',
        $body + ['idempotency_key' => $key ?? (string) Str::uuid()], bearer($actor));
}

function rcvRefund(object $actor, object $booking, string $refund, array $body, ?string $key = null)
{
    return test()->postJson('/api/v1/admin/bookings/'.$booking->guid.'/refund-requests/'.$refund.'/recovery',
        $body + ['idempotency_key' => $key ?? (string) Str::uuid()], bearer($actor));
}

function rcvEntitlement(object $booking): string
{
    return app(PaymentEligibility::class)->entitlement(DB::table('bookings')->where('guid', $booking->guid)->first());
}

function rcvAudit(object $booking): array
{
    return DB::table('payment_recovery_actions')->where('booking_guid', $booking->guid)->orderBy('created_at')->orderBy('guid')->get()->all();
}

function rcvIntent(object $booking): object
{
    return DB::table('payment_intents')->where('booking_guid', $booking->guid)->first();
}

function rcvBooking(object $booking): object
{
    return DB::table('bookings')->where('guid', $booking->guid)->first();
}

function rcvHeldQuantity(object $booking): int
{
    return (int) DB::table('bookings')->where('destination_quota_guid', $booking->destination_quota_guid)
        ->whereIn('status', BookingRules::HELD)->sum('quantity');
}

/** A payable booking whose QRIS attempt is under fraud review while the provider already shows a settlement. */
function rcvReviewedSettlement(string $reason = 'fraud_status_review'): array
{
    [$owner, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, 'pending', 'qris', 'qris');
    app(PaymentSettlementService::class)->flagAmbiguousProviderOutcome($attempt->guid, $reason);
    test()->travelTo(now()->addMinute());
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement'));

    return [$owner, $booking, $attempt];
}

it('confirms a reviewed payment as paid only from a verified capture, with the operator evidence audited', function () {
    [, $booking, $attempt] = rcvReviewedSettlement();
    $superadmin = makeUser(2);
    expect(rcvIntent($booking)->status)->toBe('needs_review')->and(rcvIntent($booking)->review_reason)->toBe('fraud_status_review');

    $response = rcvPayment($superadmin, $booking, ['action' => 'confirm_paid', 'attempt_guid' => $attempt->guid,
        'evidence_type' => 'provider_dashboard_settlement', 'evidence_reference' => $attempt->provider_reference,
        'evidence_note' => 'Challenge diterima pengelola di dashboard Midtrans']);

    $response->assertOk()->assertJsonPath('data.outcome', 'applied')->assertJsonPath('data.intent_status', 'paid')->assertJsonPath('data.booking_status', 'confirmed');
    $intent = rcvIntent($booking);
    $fresh = rcvBooking($booking);
    expect($intent->status)->toBe('paid')->and($intent->applied_attempt_guid)->toBe($attempt->guid)->and($intent->review_reason)->toBeNull()
        ->and(app(PaymentEligibility::class)->entitlement($fresh))->toBe('paid')
        ->and(DB::table('payment_events')->where('payment_attempt_guid', $attempt->guid)->where('event_type', 'capture')->value('disposition'))->toBe('applied');
    $audit = rcvAudit($booking);
    expect($audit)->toHaveCount(1)->and($audit[0]->action)->toBe('confirm_paid')->and($audit[0]->outcome)->toBe('applied')->and($audit[0]->actor_guid)->toBe($superadmin->guid)
        ->and($audit[0]->evidence_reference)->toBe($attempt->provider_reference)->and($audit[0]->from_status)->toBe('needs_review')->and($audit[0]->to_status)->toBe('paid')
        ->and(json_decode($audit[0]->verified_facts, true)['capture_event_guid'])->not->toBeEmpty();
});

it('refuses to confirm a payment without verified evidence and leaves the investigation and quota hold intact', function (string $case) {
    [, $booking, $attempt] = rcvReviewedSettlement($case === 'identity' ? 'transaction_mismatch' : 'fraud_status_review');
    $superadmin = makeUser(2);
    $body = ['action' => 'confirm_paid', 'attempt_guid' => $attempt->guid, 'evidence_type' => 'provider_dashboard_settlement',
        'evidence_reference' => $attempt->provider_reference, 'evidence_note' => 'Diperiksa pengelola pada dashboard'];
    match ($case) {
        'reference' => $body['evidence_reference'] = 'trx-someone-else',
        'pending' => mtxFakeStatus(mtxStatusBody($attempt, 'pending')),
        'late' => (function () use ($booking, $attempt): void {
            $this->travelTo(CarbonImmutable::parse(rcvBooking($booking)->payment_expires_at, 'UTC')->addMinute());
            mtxFakeStatus(mtxStatusBody($attempt, 'settlement'));
        })(),
        'refunded' => mtxFakeStatus(mtxStatusBody($attempt, 'refund')),
        default => null,
    };

    rcvPayment($superadmin, $booking, $body)->assertConflict()->assertJsonPath('code', 'insufficient_evidence');

    expect(rcvIntent($booking)->status)->toBe('needs_review')->and(rcvIntent($booking)->applied_attempt_guid)->toBeNull()
        ->and(in_array(rcvBooking($booking)->status, ['confirmed', 'expired'], true))->toBeFalse()->and(rcvHeldQuantity($booking))->toBeGreaterThan(0);
    $audit = rcvAudit($booking);
    expect($audit)->toHaveCount(1)->and($audit[0]->outcome)->toBe('refused')->and($audit[0]->refusal_reason)->not->toBeEmpty();
})->with(['reference', 'pending', 'late', 'identity', 'refunded']);

it('applies role and destination scope to every recovery action', function () {
    [, $booking, $attempt] = rcvReviewedSettlement();
    $scoped = rcvScopedAdmin($booking);
    $outsider = makeUser(1);
    $body = ['action' => 'confirm_paid', 'attempt_guid' => $attempt->guid, 'evidence_type' => 'provider_dashboard_settlement',
        'evidence_reference' => $attempt->provider_reference, 'evidence_note' => 'Diperiksa pengelola pada dashboard'];

    rcvPayment(makeUser(0), $booking, $body)->assertStatus(403);
    rcvPayment($outsider, $booking, ['action' => 'reverify'])->assertStatus(404);
    rcvPayment($outsider, $booking, $body)->assertStatus(404);
    rcvPayment($scoped, $booking, $body)->assertStatus(403);
    rcvPayment($scoped, $booking, ['action' => 'reverify'])->assertOk();

    expect(rcvIntent($booking)->status)->toBe('needs_review');
    Http::assertSentCount(1);
});

it('rejects an operator payload that tries to dictate an outcome', function () {
    [, $booking, $attempt] = rcvReviewedSettlement();

    rcvPayment(makeUser(2), $booking, ['action' => 'confirm_paid', 'attempt_guid' => $attempt->guid, 'status' => 'paid'])->assertUnprocessable();
    rcvPayment(makeUser(2), $booking, ['action' => 'confirm_paid', 'attempt_guid' => $attempt->guid])->assertUnprocessable();
    rcvPayment(makeUser(2), $booking, ['action' => 'set_paid', 'attempt_guid' => $attempt->guid])->assertUnprocessable();
    expect(rcvIntent($booking)->status)->toBe('needs_review');
});

it('replays an applied action for the same key and rejects the same key with different input', function () {
    [, $booking, $attempt] = rcvReviewedSettlement();
    $superadmin = makeUser(2);
    $body = ['action' => 'confirm_paid', 'attempt_guid' => $attempt->guid, 'evidence_type' => 'provider_dashboard_settlement',
        'evidence_reference' => $attempt->provider_reference, 'evidence_note' => 'Challenge diterima pengelola di dashboard'];

    $first = rcvPayment($superadmin, $booking, $body, 'key-confirm-0001')->assertOk();
    $calls = count(Http::recorded());
    $replay = rcvPayment($superadmin, $booking, $body, 'key-confirm-0001')->assertOk();
    rcvPayment($superadmin, $booking, ['evidence_note' => 'Catatan lain yang berbeda'] + $body, 'key-confirm-0001')->assertConflict()->assertJsonPath('code', 'idempotency_conflict');

    expect($replay->json('data.audit_guid'))->toBe($first->json('data.audit_guid'))->and(count(Http::recorded()))->toBe($calls)
        ->and(rcvAudit($booking))->toHaveCount(1);
});

it('re-verifies an unknown attempt through the Status API without changing any state on an unproven answer', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, 'unknown', 'virtual_account', 'bca', ['provider_reference' => null]);
    mtxFakeStatus(['status_code' => '404', 'status_message' => "Transaction doesn't exist."]);
    $admin = rcvScopedAdmin($booking);

    rcvPayment($admin, $booking, ['action' => 'reverify', 'attempt_guid' => $attempt->guid])->assertOk()->assertJsonPath('data.attempts.0.status', 'unknown');

    $row = DB::table('payment_attempts')->where('guid', $attempt->guid)->first();
    expect($row->status)->toBe('unknown')->and($row->last_error_code)->toBe('provider_order_not_found')->and(rcvBooking($booking)->status)->toBe('awaiting_payment')
        ->and(rcvAudit($booking)[0]->action)->toBe('reverify');
});

it('never treats repeated provider 404 answers as proof, however many rounds pass', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, 'unknown', 'virtual_account', 'bca', ['provider_reference' => null]);
    mtxFakeStatus(['status_code' => '404', 'status_message' => "Transaction doesn't exist."]);

    for ($round = 0; $round < 8; $round++) {
        $this->travelTo(now()->addMinutes(20));
        DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['next_reconcile_at' => now()->subSecond()]);
        app(MidtransPaymentService::class)->reconcile($attempt->guid);
        app(BookingService::class)->expireDue();
    }

    expect(DB::table('payment_attempts')->where('guid', $attempt->guid)->value('status'))->toBe('unknown')
        ->and(in_array(rcvBooking($booking)->status, ['expired', 'confirmed'], true))->toBeFalse()->and(rcvHeldQuantity($booking))->toBeGreaterThan(0);
});

it('bounds scheduled recovery of an unknown Charge, escalates it, and keeps the quota held without any new Charge', function () {
    [$owner, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, 'unknown', 'virtual_account', 'bca', ['provider_reference' => null]);
    mtxFakeStatus(['status_code' => '404', 'status_message' => "Transaction doesn't exist."]);
    $deadline = CarbonImmutable::parse(rcvBooking($booking)->payment_expires_at, 'UTC');

    $this->travelTo($deadline->addHour());
    DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['next_reconcile_at' => now()->subSecond()]);
    app(MidtransPaymentService::class)->reconcile($attempt->guid);
    $early = DB::table('payment_attempts')->where('guid', $attempt->guid)->first();
    expect($early->recovery_exhausted_at)->toBeNull()->and(CarbonImmutable::parse($early->next_reconcile_at, 'UTC')->gt(now()))->toBeTrue();

    $this->travelTo($deadline->addHours(7));
    DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['next_reconcile_at' => now()->subSecond()]);
    app(MidtransPaymentService::class)->reconcile($attempt->guid);

    $row = DB::table('payment_attempts')->where('guid', $attempt->guid)->first();
    expect($row->recovery_exhausted_at)->not->toBeNull()->and($row->next_reconcile_at)->toBeNull()->and($row->escalated_at)->not->toBeNull()
        ->and($row->status)->toBe('unknown');
    app(BookingService::class)->expireDue();
    mtxStart($owner, $booking, 'after-exhaustion');
    expect(rcvHeldQuantity($booking))->toBeGreaterThan(0)->and(DB::table('payment_attempts')->count())->toBe(1);
    Http::assertNotSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/v2/charge'));
});

it('lets a superadmin close an unknown attempt with no provider order only after the grace, a fresh 404 and dashboard evidence', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, 'unknown', 'virtual_account', 'bca', ['provider_reference' => null, 'last_error_code' => 'provider_order_not_found']);
    mtxFakeStatus(['status_code' => '404', 'status_message' => "Transaction doesn't exist."]);
    $superadmin = makeUser(2);
    $row = rcvBooking($booking);
    $body = ['action' => 'close_unpaid', 'attempt_guid' => $attempt->guid, 'evidence_type' => 'provider_dashboard_no_transaction',
        'evidence_reference' => 'MAP-SEARCH-0042', 'evidence_note' => 'Order tidak ditemukan di dashboard Midtrans'];

    $this->travelTo(CarbonImmutable::parse($row->payment_expires_at, 'UTC')->addMinute());
    rcvPayment($superadmin, $booking, $body)->assertConflict()->assertJsonPath('code', 'insufficient_evidence');
    expect(DB::table('payment_attempts')->where('guid', $attempt->guid)->value('status'))->toBe('unknown')->and(rcvBooking($booking)->status)->not->toBe('expired');

    $this->travelTo(CarbonImmutable::parse($row->notification_grace_expires_at, 'UTC')->addMinute());
    rcvPayment(rcvScopedAdmin($booking), $booking, $body)->assertStatus(403);
    rcvPayment($superadmin, $booking, ['evidence_type' => null] + $body)->assertUnprocessable();
    rcvPayment($superadmin, $booking, $body)->assertOk()->assertJsonPath('data.booking_status', 'expired');

    $closed = DB::table('payment_attempts')->where('guid', $attempt->guid)->first();
    expect($closed->status)->toBe('failed')->and($closed->last_error_code)->toBe('operator_closed_no_provider_order')->and($closed->finished_at)->not->toBeNull()
        ->and(rcvIntent($booking)->status)->toBe('expired')->and(rcvHeldQuantity($booking))->toBe(0)
        ->and(DB::table('payment_events')->where('payment_attempt_guid', $attempt->guid)->count())->toBe(0);
});

it('refuses to close an attempt when the fresh lookup does not prove the order is absent', function (string $answer) {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, 'unknown', 'virtual_account', 'bca', ['provider_reference' => null, 'last_error_code' => 'provider_order_not_found']);
    $this->travelTo(CarbonImmutable::parse(rcvBooking($booking)->notification_grace_expires_at, 'UTC')->addMinute());
    match ($answer) {
        'unavailable' => mtxFakeStatus(['message' => 'boom'], 503),
        'exists' => mtxFakeStatus(mtxStatusBody($attempt, 'pending', ['transaction_status' => 'pending'])),
        default => mtxFakeStatus(mtxStatusBody($attempt, 'settlement')),
    };

    rcvPayment(makeUser(2), $booking, ['action' => 'close_unpaid', 'attempt_guid' => $attempt->guid, 'evidence_type' => 'provider_dashboard_no_transaction',
        'evidence_reference' => 'MAP-SEARCH-0042', 'evidence_note' => 'Order tidak ditemukan di dashboard Midtrans'])->assertConflict();

    expect(DB::table('payment_attempts')->where('guid', $attempt->guid)->value('status'))->not->toBe('failed')
        ->and(rcvBooking($booking)->status)->not->toBe('expired')->and(rcvHeldQuantity($booking))->toBeGreaterThan(0);
})->with(['unavailable', 'exists', 'settled']);

it('closes a reviewed payment as unpaid only when every attempt is proven terminal unpaid', function () {
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, 'expired', 'virtual_account', 'bca', ['finished_at' => now()]);
    app(PaymentSettlementService::class)->flagAmbiguousProviderOutcome($attempt->guid, 'provider_response_invalid');
    $pending = mtxAttempt($booking, 'pending', 'qris', 'qris');
    $this->travelTo(CarbonImmutable::parse(rcvBooking($booking)->payment_expires_at, 'UTC')->addMinute());
    $body = ['action' => 'close_unpaid', 'evidence_type' => 'verified_terminal_status', 'evidence_reference' => 'CASE-2026-1101',
        'evidence_note' => 'Semua percobaan terbukti kedaluwarsa'];

    rcvPayment(makeUser(2), $booking, $body)->assertConflict()->assertJsonPath('code', 'insufficient_evidence');
    expect(rcvIntent($booking)->status)->toBe('needs_review');

    DB::table('payment_attempts')->where('guid', $pending->guid)->update(['status' => 'expired', 'finished_at' => now()]);
    rcvPayment(makeUser(2), $booking, $body)->assertOk()->assertJsonPath('data.booking_status', 'expired');

    expect(rcvIntent($booking)->status)->toBe('expired')->and(rcvHeldQuantity($booking))->toBe(0);
});

it('never closes a payment as unpaid while an attempt is paid', function () {
    [, $booking, $attempt] = rcvReviewedSettlement();
    rcvPayment(makeUser(2), $booking, ['action' => 'reverify'])->assertOk();

    rcvPayment(makeUser(2), $booking, ['action' => 'close_unpaid', 'evidence_type' => 'verified_terminal_status', 'evidence_reference' => 'CASE-2026-1102',
        'evidence_note' => 'Dicoba menutup pembayaran yang sudah dibayar'])->assertConflict()->assertJsonPath('code', 'insufficient_evidence');

    expect(rcvIntent($booking)->status)->toBe('needs_review')->and(DB::table('payment_attempts')->where('guid', $attempt->guid)->value('status'))->toBe('paid');
});

it('shows a booking recovery view with the allowed actions per role and the audit trail', function () {
    [, $booking, $attempt] = rcvReviewedSettlement();
    $scoped = rcvScopedAdmin($booking);
    rcvPayment($scoped, $booking, ['action' => 'note', 'evidence_note' => 'Menunggu konfirmasi dashboard Midtrans'])->assertOk();

    $view = $this->getJson('/api/v1/admin/bookings/'.$booking->guid.'/payment-recovery', bearer($scoped))->assertOk();
    $super = $this->getJson('/api/v1/admin/bookings/'.$booking->guid.'/payment-recovery', bearer(makeUser(2)))->assertOk();
    $this->getJson('/api/v1/admin/bookings/'.$booking->guid.'/payment-recovery', bearer(makeUser(1)))->assertStatus(404);

    expect($view->json('data.intent.status'))->toBe('needs_review')->and($view->json('data.intent.review_reason'))->toBe('fraud_status_review')
        ->and($view->json('data.allowed_actions'))->toContain('reverify', 'note')->and($view->json('data.allowed_actions'))->not->toContain('confirm_paid')
        ->and($super->json('data.allowed_actions'))->toContain('confirm_paid', 'close_unpaid')
        ->and($view->json('data.audit.0.action'))->toBe('note');
    $this->getJson('/api/v1/admin/payment-recovery', bearer($scoped))->assertOk()->assertJsonPath('data.cases.0.booking_guid', $booking->guid);
    $this->getJson('/api/v1/admin/payment-recovery', bearer(makeUser(1)))->assertOk()->assertJsonCount(0, 'data.cases');
});

// ---------------------------------------------------------------------------------------------------------------------------
// Refund requests in review and their admission hold
// ---------------------------------------------------------------------------------------------------------------------------

/** A QRIS refund claimed and then parked in review by an unresolved dispatch (key and admission hold intact). @return array<int, mixed> */
function rcvReviewedRefund(string $reason = 'refund_dispatch_unresolved', bool $dispatched = true): array
{
    [$owner, $booking, $attempt, $destination] = mtxPaid('qris', 'qris');
    $refund = mtxRefundAsk($owner, $booking);
    mtxRefundDecide(makeUser(2), $booking, $refund)->assertOk()->assertJsonPath('data.status', 'refund_pending');
    DB::table('booking_refund_requests')->where('guid', $refund)->update(['status' => 'needs_review', 'review_reason' => $reason, 'escalated_at' => now(),
        'next_reconcile_at' => null, 'dispatch_started_at' => $dispatched ? now() : null, 'dispatch_attempts' => $dispatched ? 5 : 0]);

    test()->travelTo(now()->addMinute());

    return [$owner, $booking, $attempt, $destination, $refund];
}

it('resumes a reviewed provider refund with the SAME key and never a new one', function () {
    [, $booking, $attempt, , $refund] = rcvReviewedRefund();
    $key = mtxRefundRow($refund)->refund_key;
    $admin = rcvScopedAdmin($booking);

    rcvRefund($admin, $booking, $refund, ['action' => 'resume_provider', 'evidence_note' => 'Konfigurasi sudah diperbaiki dan diverifikasi'])
        ->assertOk()->assertJsonPath('data.refund.status', 'refund_pending');

    $row = mtxRefundRow($refund);
    expect($row->refund_key)->toBe($key)->and((int) $row->admission_hold)->toBe(1)->and($row->refund_route)->toBe('provider_api')->and((int) $row->dispatch_attempts)->toBe(1)
        ->and($row->escalated_at)->toBeNull();
    $counter = mtxFakeRefund(mtxRefundAccepted($attempt));
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement'));
    expect(app(BookingRefundService::class)->processProviderRefund($refund))->toBe('sent');
    expect($counter->payloads[0]['refund_key'])->toBe($key);
});

it('keeps the hold and refuses to resume a refund whose review is not resumable', function () {
    [, $booking, , , $refund] = rcvReviewedRefund('partial_adjustment_review');

    rcvRefund(rcvScopedAdmin($booking), $booking, $refund, ['action' => 'resume_provider', 'evidence_note' => 'Mencoba melanjutkan tanpa dasar'])
        ->assertConflict()->assertJsonPath('code', 'insufficient_evidence');

    expect(mtxRefundRow($refund)->status)->toBe('needs_review')->and((int) mtxRefundRow($refund)->admission_hold)->toBe(1);
});

it('re-verifies a reviewed refund and lets the verified provider evidence settle it', function () {
    [, $booking, $attempt, , $refund] = rcvReviewedRefund();
    mtxFakeStatus(mtxRefundStatus($attempt, mtxRefundRow($refund)->refund_key));

    rcvRefund(rcvScopedAdmin($booking), $booking, $refund, ['action' => 'reverify'])->assertOk();

    $row = mtxRefundRow($refund);
    expect($row->status)->toBe('refunded')->and($row->admission_hold)->toBeNull()->and(DB::table('payment_attempts')->where('guid', $attempt->guid)->value('status'))->toBe('reversed');
});

it('moves a dispatched refund to the manual route only for a superadmin with a fresh still-paid verification and dashboard evidence', function () {
    [, $booking, $attempt, , $refund] = rcvReviewedRefund();
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement'));
    $body = ['action' => 'route_manual', 'evidence_type' => 'provider_dashboard_no_refund', 'evidence_reference' => 'MAP-REFUND-0007',
        'evidence_note' => 'Tidak ada refund untuk order ini di dashboard'];

    rcvRefund(rcvScopedAdmin($booking), $booking, $refund, $body)->assertStatus(403);
    rcvRefund(makeUser(2), $booking, $refund, ['evidence_type' => null] + $body)->assertUnprocessable();
    expect(mtxRefundRow($refund)->status)->toBe('needs_review')->and((int) mtxRefundRow($refund)->admission_hold)->toBe(1);

    rcvRefund(makeUser(2), $booking, $refund, $body)->assertOk()->assertJsonPath('data.refund.status', 'manual_action_required');

    $row = mtxRefundRow($refund);
    expect($row->refund_route)->toBe('manual')->and($row->admission_hold)->toBeNull()->and($row->refund_key)->toBe('PAMI-RF-'.$refund)
        ->and(rcvEntitlement($booking))->toBe('paid');
});

it('refuses the manual route when the provider shows the refund, and lets the verified refund settle instead', function () {
    [, $booking, $attempt, , $refund] = rcvReviewedRefund();
    mtxFakeStatus(mtxRefundStatus($attempt, mtxRefundRow($refund)->refund_key));

    rcvRefund(makeUser(2), $booking, $refund, ['action' => 'route_manual', 'evidence_type' => 'provider_dashboard_no_refund', 'evidence_reference' => 'MAP-REFUND-0007',
        'evidence_note' => 'Tidak ada refund untuk order ini di dashboard'])->assertConflict();

    expect(mtxRefundRow($refund)->status)->toBe('refunded')->and(DB::table('booking_refund_requests')->where('booking_guid', $booking->guid)->where('status', 'manual_action_required')->count())->toBe(0);
});

it('lets a scoped admin reject a refund of a used ticket and releases the hold, with the decision audited', function () {
    [$owner, $booking, , $destination] = mtxPaid('qris', 'qris');
    $refund = mtxRefundAsk($owner, $booking);
    mtxCheckIn(mtxVisitDay($booking, $destination))->assertOk();
    mtxRefundDecide(makeUser(2), $booking, $refund)->assertOk()->assertJsonPath('data.status', 'needs_review');
    DB::table('booking_refund_requests')->where('guid', $refund)->update(['admission_hold' => 1]);

    rcvRefund(rcvScopedAdmin($booking), $booking, $refund, ['action' => 'reject', 'evidence_type' => 'ticket_used_confirmation',
        'evidence_note' => 'Tiket sudah dipakai masuk, refund penuh tidak berlaku'])->assertOk()->assertJsonPath('data.refund.status', 'rejected');

    $row = mtxRefundRow($refund);
    expect($row->status)->toBe('rejected')->and($row->active)->toBeNull()->and($row->admission_hold)->toBeNull()
        ->and(DB::table('payment_recovery_actions')->where('subject_guid', $refund)->where('action', 'reject')->where('outcome', 'applied')->count())->toBe(1)
        ->and(rcvEntitlement($booking))->toBe('paid');
});

it('refuses to reject a dispatched provider refund when the provider shows it, keeping the hold', function () {
    [, $booking, $attempt, , $refund] = rcvReviewedRefund();
    mtxFakeStatus(mtxRefundStatus($attempt, mtxRefundRow($refund)->refund_key));

    rcvRefund(makeUser(2), $booking, $refund, ['action' => 'reject', 'evidence_type' => 'provider_dashboard_no_refund', 'evidence_reference' => 'MAP-REFUND-0007',
        'evidence_note' => 'Tidak ada refund untuk order ini di dashboard'])->assertConflict();

    expect(mtxRefundRow($refund)->status)->toBe('refunded');
    expect(DB::table('payment_recovery_actions')->where('subject_guid', $refund)->where('outcome', 'refused')->count())->toBe(1);
});

it('refuses to confirm a payment when the fresh lookup shows a chargeback or partial refund, or cannot be completed', function (string $answer) {
    [, $booking, $attempt] = rcvReviewedSettlement();
    rcvPayment(makeUser(2), $booking, ['action' => 'reverify'])->assertOk();
    expect(DB::table('payment_attempts')->where('guid', $attempt->guid)->value('status'))->toBe('paid');
    $this->travelTo(now()->addMinute());
    match ($answer) {
        'partial_refund' => mtxFakeStatus(mtxStatusBody($attempt, 'partial_refund')),
        'chargeback' => mtxFakeStatus(mtxStatusBody($attempt, 'chargeback')),
        default => mtxFakeStatus(['message' => 'boom'], 503),
    };

    rcvPayment(makeUser(2), $booking, ['action' => 'confirm_paid', 'attempt_guid' => $attempt->guid, 'evidence_type' => 'provider_dashboard_settlement',
        'evidence_reference' => $attempt->provider_reference, 'evidence_note' => 'Diperiksa pengelola pada dashboard'])->assertConflict()->assertJsonPath('code', 'insufficient_evidence');

    expect(rcvIntent($booking)->status)->toBe('needs_review')->and(rcvBooking($booking)->status)->not->toBe('confirmed');
})->with(['partial_refund', 'chargeback', 'unavailable']);

it('refuses to confirm a sandbox capture on a production deployment', function () {
    [, $booking, $attempt] = rcvReviewedSettlement('payment_not_applicable');
    rcvPayment(makeUser(2), $booking, ['action' => 'reverify'])->assertOk();
    app()->detectEnvironment(fn (): string => 'production');

    rcvPayment(makeUser(2), $booking, ['action' => 'confirm_paid', 'attempt_guid' => $attempt->guid, 'evidence_type' => 'provider_dashboard_settlement',
        'evidence_reference' => $attempt->provider_reference, 'evidence_note' => 'Diperiksa pengelola pada dashboard'])->assertConflict()->assertJsonPath('code', 'insufficient_evidence');

    expect(rcvIntent($booking)->status)->toBe('needs_review');
});

it('refuses the manual route and the rejection while the provider has accepted a refund reference', function (string $action) {
    [, $booking, $attempt, , $refund] = rcvReviewedRefund();
    DB::table('booking_refund_requests')->where('guid', $refund)->update(['provider_refund_reference' => '7']);
    mtxFakeStatus(mtxStatusBody($attempt, 'settlement'));

    rcvRefund(makeUser(2), $booking, $refund, ['action' => $action, 'evidence_type' => 'provider_dashboard_no_refund', 'evidence_reference' => 'MAP-REFUND-0007',
        'evidence_note' => 'Tidak ada refund untuk order ini di dashboard'])->assertConflict();

    expect(mtxRefundRow($refund)->status)->toBe('needs_review')->and((int) mtxRefundRow($refund)->admission_hold)->toBe(1);
})->with(['route_manual', 'reject']);

it('does not accept an attempt of another booking for a note, and maps a key race to an idempotency conflict', function () {
    [, $booking] = rcvReviewedSettlement();
    [, $other] = mtxPayable();
    $foreign = mtxAttempt($other, 'pending', 'qris', 'qris');

    rcvPayment(makeUser(2), $booking, ['action' => 'note', 'attempt_guid' => $foreign->guid, 'evidence_note' => 'Catatan untuk percobaan milik booking lain'])->assertStatus(404);

    expect(rcvAudit($booking))->toHaveCount(0);
});

it('collapses rapid repeated operator re-verification into one provider lookup', function () {
    [, $booking, $attempt] = rcvReviewedSettlement();
    $before = count(Http::recorded());

    rcvPayment(makeUser(2), $booking, ['action' => 'reverify', 'attempt_guid' => $attempt->guid])->assertOk();
    rcvPayment(makeUser(2), $booking, ['action' => 'reverify', 'attempt_guid' => $attempt->guid])->assertOk();

    expect(count(Http::recorded()) - $before)->toBe(1);
});

it('lets a serious provider signal replace a review reason an operator could clear, so the case is not confirmable', function () {
    [, $booking, $attempt] = rcvReviewedSettlement('fraud_status_review');
    expect(rcvIntent($booking)->review_reason)->toBe('fraud_status_review');

    app(PaymentSettlementService::class)->flagAmbiguousProviderOutcome($attempt->guid, 'partial_adjustment_review');
    expect(rcvIntent($booking)->review_reason)->toBe('partial_adjustment_review');

    // The reverse never happens: a clearable signal does not hide a serious one.
    app(PaymentSettlementService::class)->flagAmbiguousProviderOutcome($attempt->guid, 'fraud_status_review');
    expect(rcvIntent($booking)->review_reason)->toBe('partial_adjustment_review');

    rcvPayment(makeUser(2), $booking, ['action' => 'confirm_paid', 'attempt_guid' => $attempt->guid, 'evidence_type' => 'provider_dashboard_settlement',
        'evidence_reference' => $attempt->provider_reference, 'evidence_note' => 'Diperiksa pengelola pada dashboard'])->assertConflict();
    expect(rcvIntent($booking)->status)->toBe('needs_review');
});

it('keeps scheduling due provider refunds when Midtrans credentials are missing, so a deferred refund reaches its bound', function () {
    [, $booking, , , $refund] = rcvReviewedRefund();
    DB::table('booking_refund_requests')->where('guid', $refund)->update(['status' => 'refund_pending', 'review_reason' => null, 'next_reconcile_at' => now()->subMinute()]);
    mtxConfig(['server_key' => '']);

    $this->artisan('payments:reconcile-midtrans')->assertSuccessful();

    Queue::assertPushed(ProcessMidtransRefund::class, fn ($job): bool => true);
});
