<?php

use App\Services\BookingService;
use App\Services\MidtransPaymentService;
use App\Services\TicketService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Mysql/DisposableMysqlGuard.php';

/*
 * Opt-in live Midtrans SANDBOX UAT. Never runs in normal suites. Real HTTP goes only to api.sandbox.midtrans.com and
 * data lives only in a disposable MySQL database guarded by assertDisposableMysqlAccount. No RefreshDatabase: rows stay
 * as evidence. Step 1 (charge) creates one booking per channel; the payer then pays in the Midtrans Sandbox simulator;
 * step 2 (settle) verifies through authenticated GET Status only.
 *
 * MIDTRANS_LIVE_SANDBOX_UAT=1 MIDTRANS_UAT_STEP=charge|settle MIDTRANS_UAT_STATE=<json path>
 */
beforeEach(function () {
    if (env('MIDTRANS_LIVE_SANDBOX_UAT') !== '1') {
        $this->markTestSkipped('Live Midtrans Sandbox UAT is opt-in.');
    }
    assertDisposableMysqlAccount(config('database.connections.mysql'), 'pami_payment_uat_test');
    expect(config('midtrans.environment'))->toBe('sandbox')
        ->and(config('midtrans.production_approved'))->toBeFalse()
        ->and(str_starts_with((string) config('midtrans.server_key'), 'SB-'))->toBeTrue()
        ->and(config('midtrans.base_urls.sandbox'))->toBe('https://api.sandbox.midtrans.com')
        ->and(config('midtrans.refund.qris_api_enabled'))->toBeFalse();
});

function uatState(): array
{
    $path = (string) env('MIDTRANS_UAT_STATE');

    return is_file($path) ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [];
}

function uatLine(string $line): void
{
    fwrite(STDOUT, '[UAT] '.$line.PHP_EOL);
}

it('charges BCA VA, BNI VA and QRIS once each against the live Sandbox', function () {
    if (env('MIDTRANS_UAT_STEP') !== 'charge') {
        $this->markTestSkipped('charge step not selected');
    }
    $state = [];
    foreach ([['virtual_account', 'bca'], ['virtual_account', 'bni'], ['qris', 'qris']] as [$method, $channel]) {
        [$destination, $date] = seedDestination(capacity: 5, price: 10000, days: 5, destination: ['name' => 'UAT '.$channel.' '.Str::random(4)]);
        $owner = makeUser();
        $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date, 1) + ['idempotency_key' => (string) Str::uuid()]);
        $approvedAt = now();
        $booking = app(BookingService::class)->decide(makeUser(2), $booking->guid, true);
        $headers = bearer($owner);

        $first = $this->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts', ['method_code' => $method, 'channel' => $channel], $headers + ['Idempotency-Key' => 'uat-'.$channel.'-1']);
        $first->assertSuccessful();
        // Same key replay and a fresh key must both return the same open attempt: no second Charge, single intent.
        $replay = $this->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts', ['method_code' => $method, 'channel' => $channel], $headers + ['Idempotency-Key' => 'uat-'.$channel.'-1']);
        $fresh = $this->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts', ['method_code' => $method, 'channel' => $channel], $headers + ['Idempotency-Key' => 'uat-'.$channel.'-2']);
        $attempt = DB::table('payment_attempts')->where('guid', $first->json('data.attempt_guid'))->first();
        $deadlineMinutes = round((strtotime($fresh->json('data.expires_at')) - $approvedAt->getTimestamp()) / 60, 1);

        expect($first->json('data.status'))->toBe('pending')
            ->and($first->json('data.environment'))->toBe('sandbox')->and($first->json('data.test_only'))->toBeTrue()
            ->and($first->json('data.amount'))->toBe(10000)->and($first->json('data.currency'))->toBe('IDR')
            ->and($replay->json('data.attempt_guid'))->toBe($attempt->guid)
            ->and($fresh->json('data.attempt_guid'))->toBe($attempt->guid)
            ->and(DB::table('payment_intents')->where('booking_guid', $booking->guid)->count())->toBe(1)
            ->and(DB::table('payment_attempts')->where('payment_intent_guid', $attempt->payment_intent_guid)->count())->toBe(1)
            ->and(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBe('awaiting_payment')
            ->and(json_encode($first->json()))->not->toContain('SB-Mid-server');

        $instruction = $first->json('data.instruction');
        $payable = $method === 'qris'
            ? 'https://api.sandbox.midtrans.com/v2/qris/'.$attempt->provider_reference.'/qr-code'
            : ($instruction['va_number'] ?? json_encode($instruction));
        uatLine(sprintf('%s booking=%s attempt=%s order=%s txn=%s merchant_bound=%s deadline_min=%s instruction_keys=%s pay=%s',
            $channel, $booking->guid, $attempt->guid, $attempt->merchant_reference, $attempt->provider_reference,
            $attempt->merchant_id ?? 'null', $deadlineMinutes, implode(',', array_keys($instruction)), $payable));
        $state[$channel] = ['owner' => $owner->guid, 'booking' => $booking->guid, 'attempt' => $attempt->guid, 'order' => $attempt->merchant_reference, 'pay' => $payable];
    }
    file_put_contents((string) env('MIDTRANS_UAT_STATE'), json_encode($state, JSON_PRETTY_PRINT));
});

it('verifies each paid order through authenticated GET Status and issues tickets exactly once', function () {
    if (env('MIDTRANS_UAT_STEP') !== 'settle') {
        $this->markTestSkipped('settle step not selected');
    }
    foreach (uatState() as $channel => $row) {
        $owner = \App\Models\User::findOrFail($row['owner']);
        $before = DB::table('payment_attempts')->where('guid', $row['attempt'])->value('status');
        $this->postJson('/api/v1/bookings/'.$row['booking'].'/payment-attempts/'.$row['attempt'].'/refresh', [], bearer($owner))->assertSuccessful();
        // Second refresh is a duplicate verification: must not create a second ticket set or event.
        $this->travel(2)->minutes();
        app(MidtransPaymentService::class)->reconcile($row['attempt']);

        $attempt = DB::table('payment_attempts')->where('guid', $row['attempt'])->first();
        $intent = DB::table('payment_intents')->where('guid', $attempt->payment_intent_guid)->first();
        $booking = DB::table('bookings')->where('guid', $row['booking'])->first();
        $events = DB::table('payment_events')->where('payment_attempt_guid', $attempt->guid)->get(['verification', 'event_type', 'disposition', 'amount', 'currency']);
        $tickets = DB::table('booking_tickets')->where('booking_guid', $row['booking'])->count();
        uatLine(sprintf('%s before=%s attempt=%s intent=%s booking=%s verified_paid_at=%s applied=%s events=%s tickets=%d',
            $channel, $before, $attempt->status, $intent->status, $booking->status, $attempt->verified_paid_at ?? 'null',
            $intent->applied_attempt_guid === $attempt->guid ? 'yes' : 'no', $events->toJson(), $tickets));
        $this->travelBack();
    }
    expect(true)->toBeTrue();
});

it('expires the unpaid QRIS booking only after live Status proves the order terminal', function () {
    if (env('MIDTRANS_UAT_STEP') !== 'expire') {
        $this->markTestSkipped('expire step not selected');
    }
    $row = uatState()['qris'];
    $deadline = DB::table('bookings')->where('guid', $row['booking'])->value('payment_expires_at');
    expect(now()->gte($deadline))->toBeTrue();
    $before = [DB::table('payment_attempts')->where('guid', $row['attempt'])->value('status'), DB::table('bookings')->where('guid', $row['booking'])->value('status')];

    app(BookingService::class)->expireDue(); // before Status proof: an open attempt must keep the quota held
    $held = DB::table('bookings')->where('guid', $row['booking'])->value('status');
    app(MidtransPaymentService::class)->reconcile($row['attempt']);
    app(BookingService::class)->expireDue();
    app(BookingService::class)->expireDue(); // second pass must be a no-op

    $attempt = DB::table('payment_attempts')->where('guid', $row['attempt'])->first();
    $booking = DB::table('bookings')->where('guid', $row['booking'])->first();
    uatLine(sprintf('qris deadline=%s before=%s held_after_first_pass=%s attempt=%s intent=%s booking=%s issued_tickets=%d events=%s',
        $deadline, json_encode($before), $held, $attempt->status, DB::table('payment_intents')->where('guid', $attempt->payment_intent_guid)->value('status'),
        $booking->status, DB::table('booking_tickets')->where('booking_guid', $row['booking'])->whereNotNull('qr_token_hash')->count(),
        DB::table('payment_events')->where('payment_attempt_guid', $attempt->guid)->get(['verification', 'event_type', 'disposition'])->toJson()));
    expect($attempt->status)->not->toBe('paid')->and($booking->status)->not->toBe('confirmed');
});

it('refunds the live-paid BNI VA booking manually, in full, once, and revokes its admission', function () {
    if (env('MIDTRANS_UAT_STEP') !== 'refund') {
        $this->markTestSkipped('refund step not selected');
    }
    $row = uatState()['bni'];
    $owner = \App\Models\User::findOrFail($row['owner']);
    $booking = DB::table('bookings')->where('guid', $row['booking'])->first();
    $destination = \App\Models\Destination::findOrFail(DB::table('destination_quotas')->where('guid', $booking->destination_quota_guid)->value('destination_guid'));
    $ledger = fn () => [DB::table('payment_events')->where('payment_attempt_guid', $row['attempt'])->count(),
        (array) DB::table('payment_attempts')->where('guid', $row['attempt'])->first(), (array) DB::table('payment_intents')->where('booking_guid', $row['booking'])->first()];
    $before = $ledger();
    $refunds = '/api/v1/bookings/'.$row['booking'].'/refund-requests';

    $created = $this->postJson($refunds, ['reason' => 'UAT Phase 7 full refund', 'amount' => 1], bearer($owner) + ['Idempotency-Key' => 'uat-refund-1']);
    $replay = $this->postJson($refunds, ['reason' => 'UAT Phase 7 full refund'], bearer($owner) + ['Idempotency-Key' => 'uat-refund-1']);
    $second = $this->postJson($refunds, ['reason' => 'UAT Phase 7 full refund'], bearer($owner) + ['Idempotency-Key' => 'uat-refund-2']);
    $stranger = $this->postJson($refunds, ['reason' => 'not mine'], bearer(makeUser()) + ['Idempotency-Key' => 'uat-refund-x']);
    $refund = $created->json('data.guid') ?? $replay->json('data.guid');
    $tickets = $this->getJson('/api/v1/bookings/'.$row['booking'].'/tickets', bearer($owner));
    $decision = '/api/v1/admin/bookings/'.$row['booking'].'/refund-requests/'.$refund.'/decision';
    $unassigned = $this->postJson($decision, ['decision' => 'approve'], bearer(makeUser(1)));
    $admin = makeUser(1);
    assignTestDestination($admin, $destination);
    $approved = $this->postJson($decision, ['decision' => 'approve'], bearer($admin));
    $this->travel(1)->minutes();
    $settle = fn () => $this->postJson('/api/v1/admin/bookings/'.$row['booking'].'/refund-requests/'.$refund.'/manual-settlement', [
        'settlement_method' => 'bank_transfer', 'settlement_reference' => 'UAT-P7-BNI-0001', 'settlement_amount' => 10000,
        'settled_at' => now()->subMinute()->toIso8601String(), 'evidence_note' => 'UAT sandbox: simulated transfer evidence, no real money', 'proof_verified' => true,
    ], bearer($admin));
    $settled = $settle();
    $settledAgain = $settle();
    $after = $this->getJson('/api/v1/bookings/'.$row['booking'].'/tickets', bearer($owner));
    $this->travelBack();

    uatLine(sprintf('bni refund create=%d amount=%s status=%s replay_same=%s second=%d:%s stranger=%d hold_ticket_state=%s unassigned=%d approve=%d:%s settle=%d:%s:%s again=%d:%s after_ticket_state=%s entitlement=%s ledger_unchanged=%s active_qr=%d',
        $created->status(), json_encode($created->json('data.amount')), $created->json('data.status'), var_export($replay->json('data.guid') === $refund, true),
        $second->status(), $second->json('code'), $stranger->status(), json_encode($tickets->json('data.ticket_state') ?? $tickets->json('data.tickets.0.admission_state')),
        $unassigned->status(), $approved->status(), $approved->json('data.status'), $settled->status(), $settled->json('data.status'), $settled->json('data.refund_channel'),
        $settledAgain->status(), $settledAgain->json('data.status') ?? $settledAgain->json('code'), $after->json('data.ticket_state'), $after->json('data.entitlement'),
        var_export($ledger() === $before, true), DB::table('booking_tickets')->where('booking_guid', $row['booking'])->whereNotNull('qr_token_hash')->whereNull('qr_revoked_at')->count()));
    expect($settled->json('data.status'))->toBe('manual_refunded')->and($ledger())->toBe($before);
});

/** Notification signed exactly as Midtrans signs it. Not provider-originated: the backend must still trust only live Status. */
function uatNotify(string $order, string $status, string $code = '200', ?string $signature = null)
{
    $body = ['order_id' => $order, 'status_code' => $code, 'gross_amount' => '10000.00', 'transaction_status' => $status, 'fraud_status' => 'accept'];
    $body['signature_key'] = $signature ?? hash('sha512', $order.$code.'10000.00'.config('midtrans.server_key'));

    return test()->postJson('/api/v1/webhooks/midtrans', $body);
}

it('treats signed notifications only as triggers checked against live Status', function () {
    if (env('MIDTRANS_UAT_STEP') !== 'webhook') {
        $this->markTestSkipped('webhook step not selected');
    }
    $state = uatState();
    $snapshot = fn (string $channel) => [
        DB::table('payment_attempts')->where('guid', $state[$channel]['attempt'])->value('status'),
        DB::table('bookings')->where('guid', $state[$channel]['booking'])->value('status'),
        DB::table('booking_tickets')->where('booking_guid', $state[$channel]['booking'])->whereNotNull('qr_token_hash')->count(),
        DB::table('payment_events')->where('payment_attempt_guid', $state[$channel]['attempt'])->where('verification', 'verified')->count(),
    ];
    $bca = $snapshot('bca');

    uatNotify($state['bca']['order'], 'settlement')->assertOk();
    $this->travel(2)->minutes();
    uatNotify($state['bca']['order'], 'settlement')->assertOk(); // duplicate
    $this->travel(2)->minutes();
    uatNotify($state['bca']['order'], 'pending', '201')->assertOk(); // stale after paid
    $bad = uatNotify($state['bca']['order'], 'settlement', '200', str_repeat('a', 128));
    $unknown = uatNotify('PAMI-00000000-0000-0000-0000-000000000000', 'settlement');
    $this->travelBack();
    uatLine('bca before='.json_encode($bca).' after='.json_encode($snapshot('bca')).' wrong_signature='.$bad->status()
        .' unknown_order='.$unknown->status().' receipts='.DB::table('payment_events')->where('payment_attempt_guid', $state['bca']['attempt'])
        ->where('verification', 'unverified')->pluck('disposition')->toJson());

    // QRIS order is unpaid at the provider: a correctly signed but false settlement must not confirm anything.
    $qris = $snapshot('qris');
    uatNotify($state['qris']['order'], 'settlement')->assertOk();
    uatLine('qris forged_settlement before='.json_encode($qris).' after='.json_encode($snapshot('qris')));

    expect($snapshot('bca'))->toBe($bca)->and($bad->status())->toBe(401)
        ->and($snapshot('qris')[0])->not->toBe('paid')->and($snapshot('qris')[1])->not->toBe('confirmed');
});
