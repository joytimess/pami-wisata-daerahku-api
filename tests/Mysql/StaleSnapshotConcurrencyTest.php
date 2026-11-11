<?php

require_once __DIR__.'/DisposableMysqlGuard.php';
require_once __DIR__.'/../Feature/Support/MidtransFixtures.php';
require_once __DIR__.'/stale-snapshot-support.php';

use App\Services\BookingRefundService;
use App\Services\BookingService;
use App\Services\PaymentSettlementService;
use App\Services\TicketCheckInService;
use App\Services\TicketService;
use App\Support\BookingRules;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/*
 * Phase 4.1 stale-snapshot races on a real, disposable MySQL database (opt-in with MYSQL_CONCURRENCY_TESTS=1, guarded like the other
 * files in this directory). Every race has one STALE side and one WINNING side and is fully ordered by a lock barrier, never by sleeping:
 *
 *  1. A worker process (stale-snapshot-worker.php) takes its consistent snapshot first (outer transaction plus a plain read, or the
 *     service's own pre-lock read) and announces its MySQL connection id.
 *  2. This process takes the first lock the stale service needs (destination or quota row) in an open transaction and releases the worker.
 *  3. The process list proves that exact connection is parked in the locking statement on that table.
 *  4. The winning real service call runs in the open transaction on this connection (it already owns the barrier lock) and is committed,
 *     which also releases the barrier. Only now can the stale side resume, with a snapshot older than the winner's commit.
 * Both orders of each pair are covered by swapping which side is stale. No provider is ever contacted.
 */
function withSnsMysql(Closure $test): void
{
    if (! env('MYSQL_CONCURRENCY_TESTS')) {
        test()->markTestSkipped('Requires isolated MySQL test configuration.');
    }
    $original = config('database.connections.mysql');
    assertDisposableMysqlAccount($original);
    $database = 'pami_payment_'.strtolower(Str::random(12)).'_test';
    if (! DB::statement('CREATE DATABASE `'.$database.'`')) {
        throw new RuntimeException('Could not create disposable MySQL database.');
    }
    try {
        config(['database.connections.mysql.database' => $database]);
        DB::purge('mysql');
        assertDisposableMysqlAccount(config('database.connections.mysql'), $database);
        expect(DB::select('SHOW TABLES'))->toBeEmpty();
        expect(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]))->toBe(0);
        mtxConfig();
        Queue::fake();
        Http::preventStrayRequests();
        $test($original, $database);
    } finally {
        test()->travelBack();
        config(['database.connections.mysql' => $original]);
        DB::purge('mysql');
        if (preg_match('/^pami_payment_[a-z0-9]{12}_test$/D', $database)) {
            assertDisposableMysqlAccount($original);
            expect(DB::statement('DROP DATABASE `'.$database.'`'))->toBeTrue();
            expect((int) DB::selectOne('SELECT COUNT(*) AS total FROM information_schema.schemata WHERE schema_name = ?', [$database])->total)->toBe(0);
        }
    }
}

/** Seconds the given MySQL connection has been parked in a locking statement on the table (the proof that it is blocked). */
function snsAwaitBlocked(int $connectionId, string $table): int
{
    $deadline = microtime(true) + 25;
    do {
        foreach (DB::select('SHOW FULL PROCESSLIST') as $thread) {
            $thread = (array) $thread;
            $statement = strtolower((string) ($thread['Info'] ?? ''));
            if ((int) $thread['Id'] === $connectionId && in_array($thread['Command'], ['Query', 'Execute'], true) && str_contains($statement, ' for update')
                && str_contains($statement, 'from `'.$table.'`') && (int) $thread['Time'] >= 1) {
                return (int) $thread['Time'];
            }
        }
        usleep(50000);
    } while (microtime(true) < $deadline);

    $threads = array_map(fn (object $thread): string => $thread->Id.'/'.$thread->Command.'/'.$thread->Time.'/'.$thread->State.':'.substr((string) $thread->Info, 0, 120), DB::select('SHOW FULL PROCESSLIST'));

    throw new RuntimeException('The stale worker (connection '.$connectionId.') never showed up blocked on '.$table.'. Threads: '.implode(' | ', $threads));
}

/**
 * Runs one forced stale-snapshot race.
 *
 * @param  array<string, mixed>  $job  worker job (action, booking_guid, snapshot mode and the action's inputs)
 * @param  Closure(): mixed  $winner  the real service call that must commit while the stale worker is blocked
 * @return array{result: array<string, mixed>, blocked_seconds: int}
 */
function snsRace(array $connection, string $database, array $job, string $barrierTable, string $barrierGuid, Closure $winner): array
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database;
    mkdir($directory);
    $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database,
        'DB_URL' => '', 'MYSQL_CONCURRENCY_TESTS' => '1', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array',
        'QUEUE_CONNECTION' => 'sync', 'FIREBASE_CREDENTIALS' => ''];
    foreach (['host', 'port', 'username', 'password'] as $key) {
        $environment['DB_'.strtoupper($key)] = (string) $connection[$key];
    }
    $worker = new Process([PHP_BINARY, __DIR__.'/stale-snapshot-worker.php', $directory, json_encode($job, JSON_THROW_ON_ERROR)], base_path(), $environment);
    $worker->setTimeout(120);
    $worker->start();
    try {
        $deadline = microtime(true) + 30;
        while (! is_file($directory.'/snapshot') && $worker->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(is_file($directory.'/snapshot'))->toBeTrue();
        $snapshot = json_decode(file_get_contents($directory.'/snapshot'), true, flags: JSON_THROW_ON_ERROR);

        DB::beginTransaction();
        DB::table($barrierTable)->where('guid', $barrierGuid)->lockForUpdate()->first(['guid']);
        touch($directory.'/go');
        $blocked = snsAwaitBlocked((int) $snapshot['connection_id'], $barrierTable);
        expect($worker->isRunning())->toBeTrue()->and(is_file($directory.'/result'))->toBeFalse();

        $winner();
        DB::commit();

        $worker->wait();
        if (! $worker->isSuccessful()) {
            throw new RuntimeException('The stale worker exited with '.$worker->getExitCode().': '.substr($worker->getOutput().$worker->getErrorOutput(), 0, 1500));
        }

        return ['result' => json_decode(file_get_contents($directory.'/result'), true, flags: JSON_THROW_ON_ERROR), 'blocked_seconds' => $blocked];
    } catch (Throwable $exception) {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        throw $exception;
    } finally {
        if ($worker->isRunning()) {
            $worker->stop();
        }
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}

/**
 * Every probed field in $keys must have stayed on the worker's old view for the whole call while the database moved on.
 *
 * @param  array<string, mixed>  $result
 * @param  list<string>  $keys
 */
function snsExpectStaleView(array $result, array $keys, string $bookingGuid): void
{
    $truth = snsProbe($bookingGuid);
    foreach ($keys as $key) {
        test()->assertSame($result['probe_before'][$key], $result['probe_after'][$key], $key.' changed inside the worker snapshot');
        test()->assertNotSame($truth[$key], $result['probe_after'][$key], $key.' was not stale: the snapshot already showed the committed value');
    }
}

function snsResultMessage(array $run): string
{
    return json_encode($run['result'], JSON_THROW_ON_ERROR);
}

/**
 * Paid Midtrans QRIS booking with an owner refund request, QR units issued on the local visit day and the API refund route enabled.
 *
 * @return array{admin: object, booking: object, destination: object, refund: string, payload: string, config: array<string, mixed>}
 */
function snsClaimable(): array
{
    $config = ['refund' => ['qris_api_enabled' => true, 'qris_window_days' => 14]];
    mtxConfig($config);
    [$owner, $booking, , $destination] = mtxPaid('qris', 'qris');
    $refund = mtxRefundAsk($owner, $booking);
    $admin = makeUser(2);
    $payload = mtxVisitDay($booking, $destination);

    return ['admin' => $admin, 'booking' => $booking, 'destination' => $destination, 'refund' => $refund, 'payload' => $payload, 'config' => $config];
}

// ---------------------------------------------------------------------------------------------------------------------
// Race 1: refund claim (decide -> refund_pending + admission hold) versus ticket check-in
// ---------------------------------------------------------------------------------------------------------------------

it('turns a stale refund claim into review when a check-in committed first on MySQL', function (string $snapshot) {
    withSnsMysql(function (array $connection, string $database) use ($snapshot): void {
        $fixture = snsClaimable();
        ['admin' => $admin, 'booking' => $booking, 'destination' => $destination, 'refund' => $refund] = $fixture;
        $job = ['action' => 'refund_decide', 'snapshot' => $snapshot, 'booking_guid' => $booking->guid, 'admin_guid' => $admin->guid,
            'refund_guid' => $refund, 'now' => now()->toIso8601String(), 'config' => $fixture['config']];

        $run = snsRace($connection, $database, $job, 'destinations', $destination->guid,
            fn () => app(TicketCheckInService::class)->confirm($admin, $fixture['payload'], 'ci-first'));

        expect($run['blocked_seconds'])->toBeGreaterThanOrEqual(1);
        $this->assertTrue($run['result']['ok'], snsResultMessage($run));
        $request = mtxRefundRow($refund);
        // The check-in committed first, so the claim must not reach the money path: no key, no route, no hold.
        expect($run['result']['data'])->toBe('needs_review')->and($request->status)->toBe('needs_review')
            ->and($request->review_reason)->toBe('usage_or_payment_changed')->and((int) $request->admission_hold)->toBe(0)
            ->and($request->refund_key)->toBeNull()->and($request->refund_route)->toBeNull()->and($request->dispatch_started_at)->toBeNull();
        expect(DB::table('booking_refund_request_events')->where('refund_request_guid', $refund)->where('to_status', 'refund_pending')->count())->toBe(0);
        expect(DB::table('ticket_check_ins')->where('booking_guid', $booking->guid)->count())->toBe(1);
        expect(DB::table('booking_tickets')->where('booking_guid', $booking->guid)->whereNotNull('checked_in_at')->count())->toBe(1);
        expect(DB::table('booking_tickets')->where('booking_guid', $booking->guid)->whereNotNull('qr_revoked_at')->count())->toBe(0);
        if ($snapshot === 'explicit') {
            snsExpectStaleView($run['result'], ['checked_in_tickets', 'check_in_rows'], $booking->guid);
        }
    });
})->with(['explicit outer snapshot' => 'explicit', 'natural pre-lock snapshot' => 'natural']);

it('rejects a stale check-in and writes no check-in row when the refund claim committed first on MySQL', function (string $snapshot) {
    withSnsMysql(function (array $connection, string $database) use ($snapshot): void {
        $fixture = snsClaimable();
        ['admin' => $admin, 'booking' => $booking, 'destination' => $destination, 'refund' => $refund] = $fixture;
        $job = ['action' => 'check_in', 'snapshot' => $snapshot, 'booking_guid' => $booking->guid, 'admin_guid' => $admin->guid,
            'payload' => $fixture['payload'], 'key' => 'ci-stale', 'now' => now()->toIso8601String(), 'config' => $fixture['config']];

        $run = snsRace($connection, $database, $job, 'destinations', $destination->guid,
            fn () => app(BookingRefundService::class)->decide($admin, $booking->guid, $refund, ['decision' => 'approve', 'note' => 'Disetujui pengelola']));

        expect($run['blocked_seconds'])->toBeGreaterThanOrEqual(1);
        $this->assertFalse($run['result']['ok'], snsResultMessage($run));
        expect($run['result']['code'])->toBeIn(['refund_in_progress', 'ticket_revoked']);
        $request = mtxRefundRow($refund);
        expect($request->status)->toBe('refund_pending')->and((int) $request->admission_hold)->toBe(1)->and($request->refund_key)->toBe(BookingRefundService::refundKey($refund));
        expect(DB::table('ticket_check_ins')->where('booking_guid', $booking->guid)->count())->toBe(0);
        expect(DB::table('booking_tickets')->where('booking_guid', $booking->guid)->whereNotNull('checked_in_at')->count())->toBe(0);
        expect(DB::table('idempotency_records')->where('operation', 'ticket.check_in')->where('key', 'ci-stale')->value('status'))->toBe('processing');
        if ($snapshot === 'explicit') {
            snsExpectStaleView($run['result'], ['admission_holds', 'refund_statuses'], $booking->guid);
        }
    });
})->with(['explicit outer snapshot' => 'explicit', 'natural pre-lock snapshot' => 'natural']);

// ---------------------------------------------------------------------------------------------------------------------
// Race 2: verified payment settlement versus booking expiry at the payment deadline
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Approved Rp20.000 booking one minute past its 15 minute deadline (grace still open) with one pending Midtrans attempt and one
 * verified, recorded and not yet applied provider evidence event.
 *
 * @return array{booking: object, attempt: object, event: string}
 */
function snsDeadlineFixture(string $eventType, string $outcomeStatus, CarbonInterface $occurredAt): array
{
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, 'pending', 'virtual_account', 'bca', ['next_reconcile_at' => now()->addHour(),
        'instruction_expires_at' => now()->addMinutes(5), 'started_at' => now()->subMinutes(10)]);
    $deadline = now()->subMinute();
    DB::table('bookings')->where('guid', $booking->guid)->update(['payment_expires_at' => $deadline, 'notification_grace_expires_at' => now()->addMinutes(15)]);
    DB::table('payment_intents')->where('booking_guid', $booking->guid)->update(['expires_at' => $deadline]);

    return ['booking' => app(TicketService::class)->booking($booking->guid), 'attempt' => $attempt,
        'event' => snsRecordEvidence($attempt, $eventType, $outcomeStatus, $occurredAt)];
}

/** A verified provider event as the Status API path would have recorded it, stored but not yet applied. */
function snsRecordEvidence(object $attempt, string $eventType, string $outcomeStatus, CarbonInterface $occurredAt): string
{
    $guid = (string) Str::uuid();
    $occurredAt = CarbonImmutable::instance($occurredAt)->utc()->startOfSecond();
    $outcome = ['status' => $outcomeStatus, 'merchant_reference' => $attempt->merchant_reference, 'provider_reference' => $attempt->provider_reference,
        'amount' => (int) $attempt->amount, 'currency' => 'IDR', 'occurred_at' => $occurredAt->toIso8601String(), 'method' => $attempt->method, 'channel' => $attempt->channel];
    DB::table('payment_events')->insert(['guid' => $guid, 'payment_intent_guid' => $attempt->payment_intent_guid, 'payment_attempt_guid' => $attempt->guid,
        'provider' => 'midtrans', 'environment' => 'sandbox', 'provider_event_key' => 'sns-'.$guid, 'payload_hash' => hash('sha256', $guid),
        'verification' => 'verified', 'event_type' => $eventType, 'disposition' => 'recorded', 'amount' => (int) $attempt->amount, 'currency' => 'IDR',
        'provider_occurred_at' => $occurredAt, 'received_at' => now(), 'metadata' => json_encode(['source' => 'midtrans_status', 'outcome' => $outcome], JSON_THROW_ON_ERROR)]);

    return $guid;
}

/** Quantity still held against the quota by this booking (the capacity the quota counts). */
function snsHeldQuantity(object $booking): int
{
    return (int) DB::table('bookings')->where('guid', $booking->guid)->whereIn('status', BookingRules::HELD)->sum('quantity');
}

/** Exactly one of the two terminal outcomes may exist; the other's traces must be absent. */
function snsExpectSingleOutcome(string $outcome, object $booking, object $attempt): void
{
    $row = DB::table('bookings')->where('guid', $booking->guid)->first();
    $intent = DB::table('payment_intents')->where('booking_guid', $booking->guid)->first();
    $paidAttempts = DB::table('payment_attempts')->where('payment_intent_guid', $intent->guid)->where(
        fn ($paid) => $paid->where('status', 'paid')->orWhereNotNull('verified_paid_at'))->count();
    $expiredRows = DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'expired')->count();
    $confirmedRows = DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'confirmed')->count();
    if ($outcome === 'confirmed') {
        expect($row->status)->toBe('confirmed')->and($intent->status)->toBe('paid')->and($intent->applied_attempt_guid)->toBe($attempt->guid)
            ->and($paidAttempts)->toBe(1)->and(snsHeldQuantity($booking))->toBe((int) $booking->quantity)->and($expiredRows)->toBe(0)->and($confirmedRows)->toBe(1);
        expect(DB::table('payment_events')->where('payment_attempt_guid', $attempt->guid)->where('event_type', 'capture')->value('disposition'))->toBe('applied');
        expect(DB::table('notifications')->where('booking_guid', $booking->guid)->where('type', 'booking.payment_receipt')->count())->toBe(1);

        return;
    }
    expect($row->status)->toBe('expired')->and($intent->status)->toBe('expired')->and($intent->applied_attempt_guid)->toBeNull()
        ->and($paidAttempts)->toBe(0)->and(snsHeldQuantity($booking))->toBe(0)->and($expiredRows)->toBe(1)->and($confirmedRows)->toBe(0);
    expect(DB::table('payment_attempts')->where('guid', $attempt->guid)->value('status'))->toBe('expired');
}

it('confirms the booking when a stale settlement resumes after the expiry pass committed first on MySQL', function () {
    withSnsMysql(function (array $connection, string $database): void {
        ['booking' => $booking, 'attempt' => $attempt, 'event' => $event] = snsDeadlineFixture('capture', 'paid', now()->subMinutes(5));
        $job = ['action' => 'apply_event', 'snapshot' => 'explicit', 'booking_guid' => $booking->guid, 'event_guid' => $event];

        $run = snsRace($connection, $database, $job, 'destination_quotas', $booking->destination_quota_guid, fn () => app(BookingService::class)->expireDue());

        $this->assertTrue($run['result']['ok'], snsResultMessage($run));
        // The pass only parked the booking for verification (a pending attempt can still be paid); the settlement then wins.
        expect(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'payment_verification')->count())->toBe(1);
        snsExpectSingleOutcome('confirmed', $booking, $attempt);
        snsExpectStaleView($run['result'], ['history_payment_verification'], $booking->guid);
    });
});

it('leaves a confirmed booking untouched when a stale expiry pass resumes after the settlement committed first on MySQL', function () {
    withSnsMysql(function (array $connection, string $database): void {
        ['booking' => $booking, 'attempt' => $attempt, 'event' => $event] = snsDeadlineFixture('capture', 'paid', now()->subMinutes(5));
        $job = ['action' => 'expire_due', 'snapshot' => 'explicit', 'booking_guid' => $booking->guid];

        $run = snsRace($connection, $database, $job, 'destination_quotas', $booking->destination_quota_guid, fn () => app(PaymentSettlementService::class)->apply($event));

        $this->assertTrue($run['result']['ok'], snsResultMessage($run));
        // The stale pass still saw an awaiting_payment booking past its deadline; the locked re-read must stop it.
        expect(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'payment_verification')->count())->toBe(0);
        snsExpectSingleOutcome('confirmed', $booking, $attempt);
        snsExpectStaleView($run['result'], ['booking_status', 'history_confirmed'], $booking->guid);
    });
});

it('expires the booking once when a stale proven-unpaid settlement resumes after the expiry pass committed first on MySQL', function () {
    withSnsMysql(function (array $connection, string $database): void {
        ['booking' => $booking, 'attempt' => $attempt, 'event' => $event] = snsDeadlineFixture('status', 'expired', now()->subMinutes(2));
        $job = ['action' => 'apply_event', 'snapshot' => 'explicit', 'booking_guid' => $booking->guid, 'event_guid' => $event];

        $run = snsRace($connection, $database, $job, 'destination_quotas', $booking->destination_quota_guid, fn () => app(BookingService::class)->expireDue());

        $this->assertTrue($run['result']['ok'], snsResultMessage($run));
        // The pass could not expire it (the attempt was still pending), the proof then closed it exactly once.
        expect(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'payment_verification')->count())->toBe(1);
        snsExpectSingleOutcome('expired', $booking, $attempt);
        snsExpectStaleView($run['result'], ['history_payment_verification'], $booking->guid);
    });
});

it('expires the booking once when a stale expiry pass resumes after the proven-unpaid settlement committed first on MySQL', function () {
    withSnsMysql(function (array $connection, string $database): void {
        ['booking' => $booking, 'attempt' => $attempt, 'event' => $event] = snsDeadlineFixture('status', 'expired', now()->subMinutes(2));
        $job = ['action' => 'expire_due', 'snapshot' => 'explicit', 'booking_guid' => $booking->guid];

        $run = snsRace($connection, $database, $job, 'destination_quotas', $booking->destination_quota_guid, fn () => app(PaymentSettlementService::class)->apply($event));

        $this->assertTrue($run['result']['ok'], snsResultMessage($run));
        expect(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'payment_verification')->count())->toBe(0);
        snsExpectSingleOutcome('expired', $booking, $attempt);
        snsExpectStaleView($run['result'], ['history_expired', 'booking_status'], $booking->guid);
    });
});

// ---------------------------------------------------------------------------------------------------------------------
// Race 3: manual refund settlement versus a verified provider refund
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Paid BNI virtual account booking whose approved refund waits for a manual transfer (manual_action_required) with issued QR units,
 * plus a verified, recorded and not yet applied provider refund event (a refund made in the provider dashboard).
 *
 * @return array{admin: object, booking: object, attempt: object, refund: string, event: string, settlement: array<string, mixed>, settled_at: string}
 */
function snsManualRefundFixture(): array
{
    mtxConfig();
    [$owner, $booking, $attempt, $destination] = mtxPaid('virtual_account', 'bni');
    $refund = mtxRefundAsk($owner, $booking);
    $admin = makeUser(2);
    mtxVisitDay($booking, $destination);
    mtxRefundDecide($admin, $booking, $refund)->assertOk()->assertJsonPath('data.status', 'manual_action_required');
    $event = snsRecordEvidence($attempt, 'refund', 'refunded', now()->subSeconds(10));
    $settlement = ['settlement_method' => 'bank_transfer', 'settlement_reference' => 'TRF-2026-411001', 'settlement_amount' => 20000,
        'settled_at' => now()->toIso8601String(), 'evidence_note' => 'Bukti transfer diperiksa pengelola'];
    expect(DB::table('booking_tickets')->where('booking_guid', $booking->guid)->whereNotNull('qr_token_hash')->whereNull('qr_revoked_at')->count())->toBe(2);

    return ['admin' => $admin, 'booking' => $booking, 'attempt' => $attempt, 'refund' => $refund, 'event' => $event, 'settlement' => $settlement,
        'settled_at' => now()->utc()->format('Y-m-d H:i:s')];
}

/** The provider refund stays in the ledger exactly once, applied to the attempt, whatever the refund request ended as. */
function snsExpectRefundEventKept(object $attempt, string $eventGuid): void
{
    $events = DB::table('payment_events')->where('payment_attempt_guid', $attempt->guid)->where('event_type', 'refund')->get();
    expect($events)->toHaveCount(1)->and($events->first()->guid)->toBe($eventGuid)->and($events->first()->verification)->toBe('verified')
        ->and($events->first()->disposition)->toBe('applied');
    expect(DB::table('payment_attempts')->where('guid', $attempt->guid)->value('status'))->toBe('reversed');
}

it('keeps the manual settlement and flags a stale provider refund as a duplicate when the manual settlement committed first on MySQL', function () {
    withSnsMysql(function (array $connection, string $database): void {
        $fixture = snsManualRefundFixture();
        ['admin' => $admin, 'booking' => $booking, 'attempt' => $attempt, 'refund' => $refund, 'event' => $event] = $fixture;
        $job = ['action' => 'apply_event', 'snapshot' => 'explicit', 'booking_guid' => $booking->guid, 'event_guid' => $event,
            'now' => now()->addMinutes(5)->toIso8601String()];

        $run = snsRace($connection, $database, $job, 'destination_quotas', $booking->destination_quota_guid,
            fn () => app(BookingRefundService::class)->settleManually($admin, $booking->guid, $refund, $fixture['settlement']));

        $this->assertTrue($run['result']['ok'], snsResultMessage($run));
        $request = mtxRefundRow($refund);
        expect($request->status)->toBe('manual_refunded')->and($request->review_reason)->toBe('duplicate_refund_detected')
            ->and($request->provider_correlation)->toBe($event)->and($request->settlement_reference)->toBe('TRF-2026-411001')->and($request->active)->toBeNull();
        // One settled state, one audited settlement, and the provider event is kept in the ledger rather than lost.
        expect(DB::table('booking_refund_requests')->where('booking_guid', $booking->guid)->count())->toBe(1);
        expect(DB::table('booking_refund_request_events')->where('refund_request_guid', $refund)->whereIn('to_status', ['refunded', 'manual_refunded'])
            ->whereNotIn('from_status', ['refunded', 'manual_refunded'])->count())->toBe(1);
        snsExpectRefundEventKept($attempt, $event);
        // Revoked exactly once, by the manual settlement: the later provider event (worker clock +5 minutes) did not touch the units.
        $revoked = DB::table('booking_tickets')->where('booking_guid', $booking->guid)->pluck('qr_revoked_at')->unique()->values()->all();
        expect($revoked)->toBe([$fixture['settled_at']]);
        snsExpectStaleView($run['result'], ['revoked_tickets'], $booking->guid);
    });
});

it('rejects a stale manual settlement and keeps the single provider refund when the provider refund committed first on MySQL', function () {
    withSnsMysql(function (array $connection, string $database): void {
        $fixture = snsManualRefundFixture();
        ['admin' => $admin, 'booking' => $booking, 'attempt' => $attempt, 'refund' => $refund, 'event' => $event] = $fixture;
        $job = ['action' => 'settle_manually', 'snapshot' => 'explicit', 'booking_guid' => $booking->guid, 'admin_guid' => $admin->guid,
            'refund_guid' => $refund, 'settlement' => $fixture['settlement'], 'now' => now()->addMinutes(5)->toIso8601String()];

        $run = snsRace($connection, $database, $job, 'destinations', app(TicketService::class)->booking($booking->guid)->destination_guid,
            fn () => app(PaymentSettlementService::class)->apply($event));

        $this->assertFalse($run['result']['ok'], snsResultMessage($run));
        expect($run['result']['code'])->toBe('refund_not_settleable');
        $request = mtxRefundRow($refund);
        expect($request->status)->toBe('refunded')->and($request->settlement_reference)->toBeNull()->and($request->refunded_at)->not->toBeNull()
            ->and($request->review_reason)->toBeNull()->and($request->active)->toBeNull();
        expect(DB::table('booking_refund_requests')->where('booking_guid', $booking->guid)->whereIn('status', ['refunded', 'manual_refunded'])->count())->toBe(1);
        expect(DB::table('booking_refund_request_events')->where('refund_request_guid', $refund)->whereIn('to_status', ['refunded', 'manual_refunded'])
            ->whereNotIn('from_status', ['refunded', 'manual_refunded'])->count())->toBe(1);
        snsExpectRefundEventKept($attempt, $event);
        $revoked = DB::table('booking_tickets')->where('booking_guid', $booking->guid)->pluck('qr_revoked_at')->unique()->values()->all();
        expect($revoked)->toBe([$fixture['settled_at']]);
        snsExpectStaleView($run['result'], ['settled_refunds'], $booking->guid);
    });
});
