<?php

use App\Models\Destination;
use App\Models\User;
use App\Services\BookingRefundService;
use App\Services\BookingService;
use App\Services\Doku\DokuClient;
use App\Services\DokuPaymentService;
use App\Services\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 08:00:00', 'UTC'));
    config(['operations.demo_payments_enabled' => false, 'doku.environment' => 'sandbox', 'doku.verified_channels' => ['va_bni']]);
    Http::preventStrayRequests();
});

/** @return array{0: User, 1: object, 2: string, 3: Destination} owner, booking, applied attempt guid, destination */
function refundPaidBooking(bool $pay = true): array
{
    [$destination, $date] = seedDestination(capacity: 5, price: 10000, days: 5);
    $owner = makeUser();
    $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date, 2) + ['idempotency_key' => (string) Str::uuid()]);
    app(BookingService::class)->decide(makeUser(2), $booking->guid, true);
    $booking = app(TicketService::class)->booking($booking->guid);
    $client = Mockery::mock(DokuClient::class)->makePartial();
    $client->shouldReceive('readinessReason')->andReturn(null);
    $client->shouldReceive('configured')->andReturn(true);
    $client->shouldReceive('environment')->andReturn('sandbox');
    $client->shouldReceive('expiryWindowReason')->andReturn(null);
    $client->shouldReceive('create')->andReturnUsing(fn (object $attempt) => ['status' => 'pending', 'provider_reference' => '98812'.random_int(1000000, 9999999),
        'expires_at' => $attempt->instruction_expires_at, 'instruction' => ['presentation_type' => 'virtual_account', 'bank_code' => 'bni',
            'va_number' => '988123456789', 'copyable' => true, '_provider' => ['partner_service_id' => '988', 'customer_no' => '123456789']]]);
    app()->instance(DokuClient::class, $client);
    $attempt = test()->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts', ['method_code' => 'virtual_account', 'channel' => 'bni'],
        bearer($owner) + ['Idempotency-Key' => 'start'])->assertOk()->json('data.attempt_guid');
    if ($pay) {
        refundEvent($attempt, 'paid');
    }

    return [$owner, $booking, $attempt, $destination];
}

function refundEvent(string $attemptGuid, string $status, array $overrides = []): void
{
    $attempt = DB::table('payment_attempts')->where('guid', $attemptGuid)->first();
    app(DokuPaymentService::class)->receive(array_replace(['status' => $status, 'merchant_reference' => $attempt->merchant_reference,
        'provider_reference' => $attempt->provider_reference, 'amount' => (int) $attempt->amount, 'currency' => $attempt->currency,
        'occurred_at' => now()->toIso8601String(), 'event_key' => $status.'-'.$attemptGuid, 'payload_hash' => hash('sha256', $status.'-'.$attemptGuid)], $overrides));
}

function refundRequest(User $owner, object $booking, string $key = 'refund-1', string $reason = 'Perubahan rencana perjalanan'): TestResponse
{
    return test()->postJson('/api/v1/bookings/'.$booking->guid.'/refund-requests', ['reason' => $reason], bearer($owner) + ['Idempotency-Key' => $key]);
}

function refundAdmin(Destination $destination): User
{
    $admin = makeUser(1);
    assignTestDestination($admin, $destination);

    return $admin;
}

function refundDecision(User $admin, object $booking, string $refund, array $body): TestResponse
{
    return test()->postJson('/api/v1/admin/bookings/'.$booking->guid.'/refund-requests/'.$refund.'/decision', $body, bearer($admin));
}

it('creates one full-amount request per paid booking from server data and replays the same idempotency key', function () {
    [$owner, $booking, $attempt] = refundPaidBooking();

    $created = refundRequest($owner, $booking)->assertOk()->assertJsonPath('data.status', 'requested')->assertJsonPath('data.amount', 20000)
        ->assertJsonPath('data.currency', 'IDR');

    $guid = $created->json('data.guid');
    refundRequest($owner, $booking)->assertOk()->assertJsonPath('data.guid', $guid);
    refundRequest($owner, $booking, 'refund-1', 'Alasan lain yang berbeda')->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
    refundRequest($owner, $booking, 'refund-2')->assertConflict()->assertJsonPath('code', 'refund_already_requested');
    $row = DB::table('booking_refund_requests')->where('booking_guid', $booking->guid)->first();
    expect(DB::table('booking_refund_requests')->count())->toBe(1)->and($row->payment_attempt_guid)->toBe($attempt)->and($row->requested_by_user_guid)->toBe($owner->guid);
    expect(DB::table('booking_refund_request_events')->where('refund_request_guid', $guid)->pluck('to_status')->all())->toBe(['requested']);
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/payment-intent', bearer($owner))->assertJsonPath('data.refund_request.status', 'requested');
});

it('refuses client-controlled money fields, strangers, unpaid bookings and used admission', function () {
    [$owner, $booking] = refundPaidBooking();
    $headers = bearer($owner) + ['Idempotency-Key' => 'x'];
    $path = '/api/v1/bookings/'.$booking->guid.'/refund-requests';

    $this->postJson($path, ['reason' => 'Alasan yang sah', 'amount' => 1], $headers)->assertUnprocessable()->assertJsonValidationErrors('amount');
    $this->postJson($path, ['reason' => 'Alasan yang sah', 'status' => 'refunded'], $headers)->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->postJson($path, ['reason' => 'Alasan yang sah'], bearer($owner))->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    $this->postJson($path, ['reason' => 'Alasan yang sah'], bearer(makeUser()) + ['Idempotency-Key' => 'x'])->assertNotFound();
    $this->postJson($path, ['reason' => 'Alasan yang sah'], bearer(makeUser(1)) + ['Idempotency-Key' => 'x'])->assertNotFound();
    $this->postJson($path, ['reason' => 'Alasan yang sah'])->assertUnauthorized();
    expect(DB::table('booking_refund_requests')->count())->toBe(0);

    [$unpaidOwner, $unpaid] = refundPaidBooking(pay: false);
    refundRequest($unpaidOwner, $unpaid)->assertConflict()->assertJsonPath('code', 'refund_not_available');

    app(TicketService::class)->issue($booking->guid);
    $ticket = DB::table('booking_tickets')->where('booking_guid', $booking->guid)->orderBy('sequence')->first();
    DB::table('booking_tickets')->where('guid', $ticket->guid)->update(['checked_in_at' => now()]);
    refundRequest($owner, $booking)->assertConflict()->assertJsonPath('code', 'ticket_already_used');
    expect(DB::table('booking_refund_requests')->count())->toBe(0);
});

it('lets only the assigned admin decide, approves into a manual action and never marks the payment refunded', function () {
    [$owner, $booking, $attempt, $destination] = refundPaidBooking();
    $refund = refundRequest($owner, $booking)->assertOk()->json('data.guid');
    $before = ['booking' => DB::table('bookings')->where('guid', $booking->guid)->value('status'),
        'intent' => DB::table('payment_intents')->where('booking_guid', $booking->guid)->value('status')];

    refundDecision(makeUser(1), $booking, $refund, ['decision' => 'approve'])->assertNotFound();
    refundDecision($owner, $booking, $refund, ['decision' => 'approve'])->assertForbidden();
    refundDecision(refundAdmin($destination), $booking, $refund, ['decision' => 'maybe'])->assertUnprocessable();
    refundDecision(refundAdmin($destination), $booking, $refund, ['decision' => 'approve', 'status' => 'refunded'])->assertUnprocessable();
    expect(DB::table('booking_refund_requests')->where('guid', $refund)->value('status'))->toBe('requested');

    $admin = refundAdmin($destination);
    refundDecision($admin, $booking, $refund, ['decision' => 'approve', 'note' => 'Disetujui'])->assertOk()->assertJsonPath('data.status', 'manual_action_required');
    refundDecision($admin, $booking, $refund, ['decision' => 'reject', 'note' => 'Terlambat'])->assertConflict()->assertJsonPath('code', 'refund_not_pending');

    expect(DB::table('booking_refund_request_events')->where('refund_request_guid', $refund)->orderBy('created_at')->orderBy('to_status')->pluck('to_status')->sort()->values()->all())
        ->toBe(['approved', 'manual_action_required', 'requested']);
    expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBe($before['booking'])
        ->and(DB::table('payment_intents')->where('booking_guid', $booking->guid)->value('status'))->toBe($before['intent'])
        ->and(DB::table('payment_attempts')->where('guid', $attempt)->value('status'))->toBe('paid');
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/payment-intent', bearer($owner))->assertJsonPath('data.refund_request.status', 'manual_action_required')
        ->assertJsonPath('data.paid_at', '2026-11-08T08:00:00+00:00');
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/refund-requests', bearer($owner))->assertJsonPath('data.refund_requests.0.status', 'manual_action_required')
        ->assertJsonMissingPath('data.refund_requests.0.requested_by_user_guid');
    $this->getJson('/api/v1/admin/bookings/'.$booking->guid.'/refund-requests', bearer($admin))->assertJsonPath('data.refund_requests.0.review_reason', null);
    $this->getJson('/api/v1/admin/bookings/'.$booking->guid.'/refund-requests', bearer(makeUser(1)))->assertNotFound();
});

it('rejects with a required note and frees the slot for a later request', function () {
    [$owner, $booking, , $destination] = refundPaidBooking();
    $refund = refundRequest($owner, $booking)->json('data.guid');
    $admin = refundAdmin($destination);

    refundDecision($admin, $booking, $refund, ['decision' => 'reject'])->assertUnprocessable()->assertJsonValidationErrors('note');
    refundDecision($admin, $booking, $refund, ['decision' => 'reject', 'note' => 'Di luar kebijakan'])->assertOk()->assertJsonPath('data.status', 'rejected');

    $this->getJson('/api/v1/bookings/'.$booking->guid.'/refund-requests', bearer($owner))->assertJsonPath('data.refund_requests.0.decision_note', 'Di luar kebijakan');
    refundRequest($owner, $booking, 'refund-2')->assertOk()->assertJsonPath('data.status', 'requested');
    expect(DB::table('booking_refund_requests')->count())->toBe(2);
});

it('holds an approval for review when admission was used after the request', function () {
    [$owner, $booking, , $destination] = refundPaidBooking();
    $refund = refundRequest($owner, $booking)->json('data.guid');
    app(TicketService::class)->issue($booking->guid);
    DB::table('booking_tickets')->where('booking_guid', $booking->guid)->where('sequence', 1)->update(['checked_in_at' => now()]);

    refundDecision(refundAdmin($destination), $booking, $refund, ['decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'needs_review');

    expect(DB::table('booking_refund_requests')->where('guid', $refund)->first())->review_reason->toBe('usage_or_payment_changed')->active->toBe(1);
});

it('reports refunded only after a verified provider refund and preserves the paid evidence', function () {
    [$owner, $booking, $attempt, $destination] = refundPaidBooking();
    app(TicketService::class)->issue($booking->guid);
    $refund = refundRequest($owner, $booking)->json('data.guid');
    refundDecision(refundAdmin($destination), $booking, $refund, ['decision' => 'approve'])->assertOk();
    expect(DB::table('booking_tickets')->where('booking_guid', $booking->guid)->whereNotNull('qr_token_hash')->whereNull('qr_revoked_at')->count())->toBe(2);

    $this->travel(2)->minutes();
    $at = now()->toIso8601String();
    refundEvent($attempt, 'refunded', ['occurred_at' => $at]);

    $row = DB::table('booking_refund_requests')->where('guid', $refund)->first();
    expect($row->status)->toBe('refunded')->and($row->active)->toBeNull()->and($row->refunded_at)->not->toBeNull()->and($row->provider_correlation)->not->toBeNull();
    expect(DB::table('payment_attempts')->where('guid', $attempt)->first())->verified_paid_at->not->toBeNull();
    expect(DB::table('payment_events')->where('payment_attempt_guid', $attempt)->where('event_type', 'capture')->where('disposition', 'applied')->count())->toBe(1);
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/tickets', bearer($owner))->assertJsonPath('data.ticket_state', 'revoked')->assertJsonPath('data.tickets.0.qr_available', false);
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/documents', bearer($owner))->assertJsonPath('data.receipt.status', 'adjusted');
    refundEvent($attempt, 'refunded', ['occurred_at' => $at]);
    expect(DB::table('booking_refund_request_events')->where('refund_request_guid', $refund)->where('to_status', 'refunded')->count())->toBe(1);
});

it('reviews a provider refund that cannot be applied cleanly instead of inventing a full refund', function () {
    [$owner, $booking, $attempt] = refundPaidBooking();
    $refund = refundRequest($owner, $booking)->json('data.guid');

    $this->travel(2)->minutes();
    refundEvent($attempt, 'refunded', ['amount' => 5000]);

    expect(DB::table('booking_refund_requests')->where('guid', $refund)->first())->status->toBe('needs_review')->active->toBe(1);
    expect(DB::table('booking_refund_requests')->where('guid', $refund)->value('refunded_at'))->toBeNull();
});

it('holds a verified refund for review when admission had already been used', function () {
    [$owner, $booking, $attempt] = refundPaidBooking();
    $refund = refundRequest($owner, $booking)->json('data.guid');
    app(TicketService::class)->issue($booking->guid);
    DB::table('booking_tickets')->where('booking_guid', $booking->guid)->where('sequence', 1)->update(['checked_in_at' => now()]);

    $this->travel(2)->minutes();
    refundEvent($attempt, 'refunded');

    expect(DB::table('booking_refund_requests')->where('guid', $refund)->first())->status->toBe('needs_review')->review_reason->toBe('used_admission');
});

it('does not let an adjustment of another attempt close a refund request filed for the applied attempt', function () {
    [$owner, $booking, $attempt] = refundPaidBooking();
    $refund = refundRequest($owner, $booking)->json('data.guid');

    app(BookingRefundService::class)->recordVerifiedAdjustment($booking->guid, (string) Str::uuid(), (string) Str::uuid());

    expect(DB::table('booking_refund_requests')->where('guid', $refund)->first())->status->toBe('requested')->active->toBe(1)->refunded_at->toBeNull();
});

it('sends a provider reversal to review instead of reporting it as the requested refund', function () {
    [$owner, $booking, $attempt] = refundPaidBooking();
    $refund = refundRequest($owner, $booking)->json('data.guid');
    $this->travel(2)->minutes();

    refundEvent($attempt, 'reversed');

    expect(DB::table('booking_refund_requests')->where('guid', $refund)->first())->status->toBe('needs_review')->review_reason->toBe('provider_reversal')->refunded_at->toBeNull();
});

/** @return array{0: User, 1: object, 2: string, 3: string, 4: User} owner, booking, attempt, refund guid, assigned admin */
function manualRefundReady(): array
{
    [$owner, $booking, $attempt, $destination] = refundPaidBooking();
    app(TicketService::class)->issue($booking->guid);
    $refund = refundRequest($owner, $booking)->assertOk()->json('data.guid');
    $admin = refundAdmin($destination);
    refundDecision($admin, $booking, $refund, ['decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'manual_action_required');

    return [$owner, $booking, $attempt, $refund, $admin];
}

function manualSettlement(User $admin, object $booking, string $refund, array $overrides = []): TestResponse
{
    return test()->postJson('/api/v1/admin/bookings/'.$booking->guid.'/refund-requests/'.$refund.'/manual-settlement', array_replace([
        'settlement_method' => 'bank_transfer', 'settlement_reference' => 'TRX-20261108-0001', 'settlement_amount' => 20000,
        'settled_at' => now()->toIso8601String(), 'evidence_note' => 'Bukti transfer BCA diperiksa dan cocok', 'proof_verified' => true,
    ], $overrides), bearer($admin));
}

it('settles an approved refund manually with verified evidence, distinct from a provider refund, and revokes unused QR', function () {
    [$owner, $booking, $attempt, $refund, $admin] = manualRefundReady();
    $ledger = ['events' => DB::table('payment_events')->count(), 'intent' => (array) DB::table('payment_intents')->where('booking_guid', $booking->guid)->first(),
        'attempt' => (array) DB::table('payment_attempts')->where('guid', $attempt)->first()];
    expect(DB::table('booking_tickets')->where('booking_guid', $booking->guid)->whereNotNull('qr_token_hash')->whereNull('qr_revoked_at')->count())->toBe(2);

    $this->travel(2)->minutes();
    manualSettlement($admin, $booking, $refund)->assertOk()->assertJsonPath('data.status', 'manual_refunded')->assertJsonPath('data.refund_channel', 'manual')
        ->assertJsonPath('data.settlement_reference', 'TRX-20261108-0001')->assertJsonPath('data.settlement_amount', 20000)->assertJsonPath('data.settled_by_user_guid', $admin->guid);

    $row = DB::table('booking_refund_requests')->where('guid', $refund)->first();
    expect($row)->status->toBe('manual_refunded')->active->toBeNull()->refund_channel->toBe('manual')->settled_by_user_guid->toBe($admin->guid)->provider_correlation->toBeNull();
    expect(DB::table('booking_refund_request_events')->where('refund_request_guid', $refund)->where('to_status', 'manual_refunded')->first())->actor_guid->toBe($admin->guid);
    // The original payment history is untouched and no provider event was invented.
    expect(DB::table('payment_events')->count())->toBe($ledger['events'])
        ->and((array) DB::table('payment_intents')->where('booking_guid', $booking->guid)->first())->toBe($ledger['intent'])
        ->and((array) DB::table('payment_attempts')->where('guid', $attempt)->first())->toBe($ledger['attempt']);
    expect(DB::table('booking_tickets')->where('booking_guid', $booking->guid)->whereNotNull('qr_token_hash')->whereNull('qr_revoked_at')->count())->toBe(0);
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/tickets', bearer($owner))->assertJsonPath('data.ticket_state', 'revoked')->assertJsonPath('data.entitlement', 'refunded')
        ->assertJsonPath('data.tickets.0.qr_available', false);
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/refund-requests', bearer($owner))->assertJsonPath('data.refund_requests.0.status', 'manual_refunded')
        ->assertJsonPath('data.refund_requests.0.refund_channel', 'manual')->assertJsonPath('data.refund_requests.0.settlement_reference', '*************0001')
        ->assertJsonMissingPath('data.refund_requests.0.settlement_note');
    refundRequest($owner, $booking, 'refund-2')->assertConflict()->assertJsonPath('code', 'refund_already_settled');
});

it('validates manual settlement evidence, scope and state before anything changes', function () {
    [$owner, $booking, , $refund, $admin] = manualRefundReady();
    $unchanged = fn () => expect(DB::table('booking_refund_requests')->where('guid', $refund)->value('status'))->toBe('manual_action_required');

    manualSettlement($admin, $booking, $refund, ['proof_verified' => false])->assertUnprocessable()->assertJsonValidationErrors('proof_verified');
    manualSettlement($admin, $booking, $refund, ['settlement_method' => 'barter'])->assertUnprocessable()->assertJsonValidationErrors('settlement_method');
    manualSettlement($admin, $booking, $refund, ['settlement_reference' => '12'])->assertUnprocessable()->assertJsonValidationErrors('settlement_reference');
    manualSettlement($admin, $booking, $refund, ['evidence_note' => 'ok'])->assertUnprocessable()->assertJsonValidationErrors('evidence_note');
    manualSettlement($admin, $booking, $refund, ['status' => 'manual_refunded'])->assertUnprocessable();
    manualSettlement($admin, $booking, $refund, ['settled_by_user_guid' => $owner->guid])->assertUnprocessable();
    manualSettlement($admin, $booking, $refund, ['settlement_amount' => 5000])->assertConflict()->assertJsonPath('code', 'refund_amount_mismatch');
    manualSettlement($admin, $booking, $refund, ['settled_at' => now()->addHour()->toIso8601String()])->assertConflict()->assertJsonPath('code', 'refund_settlement_time_invalid');
    manualSettlement($admin, $booking, $refund, ['settled_at' => now()->subDay()->toIso8601String()])->assertConflict()->assertJsonPath('code', 'refund_settlement_time_invalid');
    manualSettlement(makeUser(1), $booking, $refund)->assertNotFound();
    manualSettlement($owner, $booking, $refund)->assertForbidden();
    test()->postJson('/api/v1/admin/bookings/'.$booking->guid.'/refund-requests/'.$refund.'/manual-settlement', [])->assertUnauthorized();
    $unchanged();
    expect(DB::table('booking_tickets')->where('booking_guid', $booking->guid)->whereNotNull('qr_token_hash')->whereNull('qr_revoked_at')->count())->toBe(2);

    // Only an approved request waiting for manual action can be settled.
    [$secondOwner, $secondBooking, , $secondDestination] = refundPaidBooking();
    $requested = refundRequest($secondOwner, $secondBooking)->json('data.guid');
    manualSettlement(refundAdmin($secondDestination), $secondBooking, $requested)->assertConflict()->assertJsonPath('code', 'refund_not_settleable');
});

it('settles each request once and never reuses a transfer reference', function () {
    [, $booking, , $refund, $admin] = manualRefundReady();
    manualSettlement($admin, $booking, $refund)->assertOk();
    manualSettlement($admin, $booking, $refund, ['settlement_reference' => 'TRX-20261108-7777'])->assertConflict()->assertJsonPath('code', 'refund_not_settleable');
    expect(DB::table('booking_refund_request_events')->where('refund_request_guid', $refund)->where('to_status', 'manual_refunded')->count())->toBe(1);

    [, $other, , $otherRefund, $otherAdmin] = manualRefundReady();
    manualSettlement($otherAdmin, $other, $otherRefund)->assertConflict()->assertJsonPath('code', 'refund_reference_reused');
    expect(DB::table('booking_refund_requests')->where('guid', $otherRefund)->value('status'))->toBe('manual_action_required');
    manualSettlement($otherAdmin, $other, $otherRefund, ['settlement_reference' => 'TRX-20261108-0002'])->assertOk();
});

it('sends a manual settlement to review when admission was used instead of refunding it', function () {
    [, $booking, , $refund, $admin] = manualRefundReady();
    DB::table('booking_tickets')->where('booking_guid', $booking->guid)->where('sequence', 1)->update(['checked_in_at' => now()]);

    manualSettlement($admin, $booking, $refund)->assertConflict()->assertJsonPath('code', 'ticket_already_used');

    expect(DB::table('booking_refund_requests')->where('guid', $refund)->first())->status->toBe('needs_review')->review_reason->toBe('used_admission')->refund_channel->toBeNull();
    expect(DB::table('booking_tickets')->where('booking_guid', $booking->guid)->whereNull('qr_revoked_at')->whereNotNull('qr_token_hash')->count())->toBe(2);
});

it('refuses a manual settlement after the provider already refunded and flags a provider refund after a manual one', function () {
    [, $booking, $attempt, $refund, $admin] = manualRefundReady();
    $this->travel(2)->minutes();
    refundEvent($attempt, 'refunded');
    expect(DB::table('booking_refund_requests')->where('guid', $refund)->value('status'))->toBe('refunded');
    manualSettlement($admin, $booking, $refund)->assertConflict()->assertJsonPath('code', 'refund_not_settleable');

    [, $second, $secondAttempt, $secondRefund, $secondAdmin] = manualRefundReady();
    manualSettlement($secondAdmin, $second, $secondRefund, ['settlement_reference' => 'TRX-20261108-0009'])->assertOk();
    $this->travel(2)->minutes();
    refundEvent($secondAttempt, 'refunded');

    $row = DB::table('booking_refund_requests')->where('guid', $secondRefund)->first();
    expect($row)->status->toBe('manual_refunded')->review_reason->toBe('duplicate_refund_detected')->provider_correlation->not->toBeNull();
    expect(DB::table('booking_refund_request_events')->where('refund_request_guid', $secondRefund)->where('note', 'duplicate_refund_detected')->count())->toBe(1);
});

it('replays an identical manual settlement, rejects an offset-less time and normalizes the reference', function () {
    [, $booking, , $refund, $admin] = manualRefundReady();
    manualSettlement($admin, $booking, $refund, ['settled_at' => '2026-11-08 08:00:00'])->assertUnprocessable()->assertJsonValidationErrors('settled_at');

    manualSettlement($admin, $booking, $refund, ['settlement_reference' => 'trx-20261108-0001'])->assertOk()->assertJsonPath('data.settlement_reference', 'TRX-20261108-0001');
    manualSettlement($admin, $booking, $refund, ['settlement_reference' => 'TRX-20261108-0001'])->assertOk()->assertJsonPath('data.status', 'manual_refunded');
    manualSettlement($admin, $booking, $refund, ['settlement_reference' => 'TRX-20261108-0099'])->assertConflict()->assertJsonPath('code', 'refund_not_settleable');
    expect(DB::table('booking_refund_request_events')->where('refund_request_guid', $refund)->where('to_status', 'manual_refunded')->count())->toBe(1)
        ->and(DB::table('booking_refund_requests')->where('guid', $refund)->value('settlement_reference'))->toBe('TRX-20261108-0001');
});
