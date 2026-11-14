<?php

require_once __DIR__.'/DisposableMysqlGuard.php';
require_once __DIR__.'/../Feature/Support/MidtransFixtures.php';

use App\Services\BookingService;
use App\Services\MidtransPaymentService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/*
 * Phase 3 concurrency proofs on a real, disposable MySQL database. Opt-in (MYSQL_CONCURRENCY_TESTS=1) and guarded exactly like the
 * other tests in this directory. Workers are separate PHP processes released together by a start file; every provider answer is
 * a faked Status body generated from the seeded attempt row, so no real provider is ever contacted.
 */

function withMtxMysql(Closure $test): void
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
        Http::preventStrayRequests();
        $test($original, $database);
    } finally {
        config(['database.connections.mysql' => $original]);
        DB::purge('mysql');
        if (preg_match('/^pami_payment_[a-z0-9]{12}_test$/D', $database)) {
            assertDisposableMysqlAccount($original);
            expect(DB::statement('DROP DATABASE `'.$database.'`'))->toBeTrue();
            expect((int) DB::selectOne('SELECT COUNT(*) AS total FROM information_schema.schemata WHERE schema_name = ?', [$database])->total)->toBe(0);
        }
    }
}

/**
 * Starts the workers, waits until every one is ready, releases them together and collects their results.
 *
 * @param  list<array<string, mixed>>  $jobs
 * @return array{results: list<array<string, mixed>>, provider_calls: int, calls_in_transaction: int}
 */
function runMtxWorkers(array $jobs, array $connection, string $database): array
{
    expect(count($jobs))->toBeLessThanOrEqual(6);
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database;
    mkdir($directory);
    $workers = [];
    $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database,
        'DB_URL' => '', 'MYSQL_CONCURRENCY_TESTS' => '1', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array',
        'QUEUE_CONNECTION' => 'sync', 'FIREBASE_CREDENTIALS' => ''];
    foreach (['host', 'port', 'username', 'password'] as $key) {
        $environment['DB_'.strtoupper($key)] = (string) $connection[$key];
    }
    try {
        foreach ($jobs as $index => $job) {
            $worker = new Process([PHP_BINARY, __DIR__.'/midtrans-payment-worker.php', $directory.'/ready-'.$index,
                $directory.'/go', json_encode($job, JSON_THROW_ON_ERROR), $directory.'/result-'.$index, $directory.'/calls'], base_path(), $environment);
            $worker->disableOutput();
            $worker->setTimeout(40);
            $worker->start();
            $workers[] = $worker;
        }
        $deadline = microtime(true) + 20;
        while (count(glob($directory.'/ready-*')) < count($jobs) && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(count(glob($directory.'/ready-*')))->toBe(count($jobs));
        touch($directory.'/go');
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue();
        }
        $calls = is_file($directory.'/calls') ? file($directory.'/calls', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

        return ['results' => array_map(fn ($index) => json_decode(file_get_contents($directory.'/result-'.$index), true, flags: JSON_THROW_ON_ERROR), array_keys($jobs)),
            'provider_calls' => count($calls), 'refund_calls' => count(array_keys($calls, 'refund', true)),
            'calls_in_transaction' => count(array_filter($calls, fn ($call) => str_ends_with($call, '-in-transaction')))];
    } finally {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}

/** @return array{0: object, 1: object, 2: object} owner, approved booking and a Midtrans attempt in the disposable database */
function mtxMysqlSeed(string $state = 'pending', array $attempt = []): array
{
    [$owner, $booking] = mtxPayable();

    return [$owner, $booking, mtxAttempt($booking, $state, 'virtual_account', 'bca', $attempt)];
}

function mtxAllOk(array $results): string
{
    return json_encode($results, JSON_THROW_ON_ERROR);
}

it('settles one attempt exactly once while claimed lookups, a notification receipt and apply workers race on MySQL', function () {
    withMtxMysql(function (array $connection, string $database): void {
        [$owner, $booking, $attempt] = mtxMysqlSeed('pending', ['next_reconcile_at' => now()->subMinute()]);
        Queue::fake();
        $notification = mtxNotification($attempt, 'settlement');
        $eventGuid = app(MidtransPaymentService::class)->receiveNotification($notification, json_encode($notification, JSON_THROW_ON_ERROR));
        $base = ['attempt_guid' => $attempt->guid, 'booking_guid' => $booking->guid, 'user_guid' => $owner->guid, 'provider_state' => 'settlement'];
        $jobs = [$base + ['action' => 'reconcile'], $base + ['action' => 'refresh'], $base + ['action' => 'process_event', 'event_guid' => $eventGuid],
            $base + ['action' => 'apply_recorded'], $base + ['action' => 'apply_recorded'], $base + ['action' => 'apply_recorded']];

        $run = runMtxWorkers($jobs, $connection, $database);

        $this->assertSame(6, collect($run['results'])->where('ok', true)->count(), mtxAllOk($run['results']));
        // The claim lets exactly one lookup reach the (fake) provider, and never from inside a database transaction.
        expect($run['provider_calls'])->toBe(1)->and($run['calls_in_transaction'])->toBe(0);
        $row = DB::table('payment_attempts')->where('guid', $attempt->guid)->first();
        expect($row->status)->toBe('paid')->and($row->verified_paid_at)->not->toBeNull()->and((int) $row->reconcile_count)->toBe(1);
        // One capture row, applied once: no duplicate_capture and no unapplied copy.
        $captures = DB::table('payment_events')->where('payment_attempt_guid', $attempt->guid)->where('event_type', 'capture')->get();
        expect($captures)->toHaveCount(1)->and($captures->first()->disposition)->toBe('applied');
        expect(DB::table('payment_events')->where('payment_attempt_guid', $attempt->guid)->where('disposition', 'duplicate_capture')->count())->toBe(0);
        $intent = DB::table('payment_intents')->where('guid', $attempt->payment_intent_guid)->first();
        expect($intent->status)->toBe('paid')->and($intent->applied_attempt_guid)->toBe($attempt->guid);
        expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBe('confirmed');
        expect(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'confirmed')->count())->toBe(1);
        expect(DB::table('notifications')->where('booking_guid', $booking->guid)->where('type', 'booking.payment_receipt')->count())->toBe(1);
    });
});

it('stores one receipt for identical signed notifications delivered in parallel without trusting them as evidence on MySQL', function () {
    withMtxMysql(function (array $connection, string $database): void {
        [, $booking, $attempt] = mtxMysqlSeed('pending');
        $notification = mtxNotification($attempt, 'settlement');
        $rawBody = json_encode($notification, JSON_THROW_ON_ERROR);
        $job = ['action' => 'receive_notification', 'payload' => $notification, 'raw_body' => $rawBody];

        $run = runMtxWorkers(array_fill(0, 5, $job), $connection, $database);

        $this->assertSame(5, collect($run['results'])->where('ok', true)->count(), mtxAllOk($run['results']));
        $key = 'notification:'.hash('sha256', implode('|', [$notification['order_id'], $notification['status_code'], $notification['gross_amount'],
            $notification['transaction_status'], $notification['fraud_status'] ?? '']));
        expect(DB::table('payment_events')->where('provider', 'midtrans')->where('provider_event_key', $key)->count())->toBe(1);
        expect(DB::table('payment_events')->count())->toBe(1);
        $receipt = DB::table('payment_events')->first();
        expect($receipt->verification)->toBe('unverified')->and($receipt->disposition)->toBe('received')->and($receipt->payment_attempt_guid)->toBe($attempt->guid);
        expect(collect($run['results'])->pluck('data')->unique()->all())->toBe([$receipt->guid]);
        // A notification is only a trigger: no lookup ran, nothing was paid or confirmed.
        expect($run['provider_calls'])->toBe(0);
        expect(DB::table('payment_attempts')->where('guid', $attempt->guid)->value('status'))->toBe('pending');
        expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBe('awaiting_payment');
    });
});

it('collapses refresh and notification lookups inside the minimum interval into one provider request on MySQL', function () {
    withMtxMysql(function (array $connection, string $database): void {
        [$owner, $booking, $attempt] = mtxMysqlSeed('pending');
        Queue::fake();
        $notification = mtxNotification($attempt, 'pending');
        $eventGuid = app(MidtransPaymentService::class)->receiveNotification($notification, json_encode($notification, JSON_THROW_ON_ERROR));
        $base = ['attempt_guid' => $attempt->guid, 'booking_guid' => $booking->guid, 'user_guid' => $owner->guid, 'provider_state' => 'pending'];
        $jobs = [$base + ['action' => 'refresh'], $base + ['action' => 'refresh'], $base + ['action' => 'refresh'],
            $base + ['action' => 'process_event', 'event_guid' => $eventGuid], $base + ['action' => 'process_event', 'event_guid' => $eventGuid]];

        $run = runMtxWorkers($jobs, $connection, $database);

        $this->assertSame(5, collect($run['results'])->where('ok', true)->count(), mtxAllOk($run['results']));
        expect($run['provider_calls'])->toBe(1)->and($run['calls_in_transaction'])->toBe(0);
        $row = DB::table('payment_attempts')->where('guid', $attempt->guid)->first();
        expect((int) $row->reconcile_count)->toBe(1)->and($row->status)->toBe('pending')->and($row->last_reconciled_at)->not->toBeNull();
        expect(DB::table('payment_events')->where('guid', $eventGuid)->value('disposition'))->toBeIn(['received', 'processed']);
        expect(DB::table('payment_events')->where('verification', 'verified')->count())->toBe(0);

        // Still inside the interval: another burst must not reach the provider or count as a lookup.
        $again = runMtxWorkers([$base + ['action' => 'refresh'], $base + ['action' => 'refresh'], $base + ['action' => 'process_event', 'event_guid' => $eventGuid]], $connection, $database);

        $this->assertSame(3, collect($again['results'])->where('ok', true)->count(), mtxAllOk($again['results']));
        expect($again['provider_calls'])->toBe(0);
        expect((int) DB::table('payment_attempts')->where('guid', $attempt->guid)->value('reconcile_count'))->toBe(1);
    });
});

it('keeps a settled attempt paid when a Charge result is saved concurrently with the settlement lookup on MySQL', function () {
    withMtxMysql(function (array $connection, string $database): void {
        // The reservation is committed (initiating); the Charge response and the provider-side settlement then arrive together.
        [$owner, $booking, $attempt] = mtxMysqlSeed('initiating', ['next_reconcile_at' => now()->subMinute()]);
        $base = ['attempt_guid' => $attempt->guid, 'booking_guid' => $booking->guid, 'user_guid' => $owner->guid, 'provider_state' => 'settlement'];
        $jobs = [$base + ['action' => 'save_initiation'], $base + ['action' => 'reconcile'],
            $base + ['action' => 'save_initiation', 'delay_ms' => 120], $base + ['action' => 'refresh']];

        $run = runMtxWorkers($jobs, $connection, $database);

        // No unique violation or other exception escapes any worker.
        $this->assertSame(4, collect($run['results'])->where('ok', true)->count(), mtxAllOk($run['results']));
        foreach ($run['results'] as $index => $result) {
            if ($jobs[$index]['action'] === 'save_initiation') {
                expect($result['data'])->toBeIn(['pending', 'paid']);
            }
        }
        expect($run['provider_calls'])->toBe(1)->and($run['calls_in_transaction'])->toBe(0);
        $row = DB::table('payment_attempts')->where('guid', $attempt->guid)->first();
        expect($row->status)->toBe('paid')->and($row->provider_reference)->toBe('trx-'.$attempt->guid)->and($row->verified_paid_at)->not->toBeNull();
        expect(DB::table('payment_events')->count())->toBe(1);
        $event = DB::table('payment_events')->first();
        expect($event->event_type)->toBe('capture')->and($event->disposition)->toBe('applied');
        expect(DB::table('payment_intents')->where('guid', $attempt->payment_intent_guid)->value('status'))->toBe('paid');
        expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBe('confirmed');
        expect(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'confirmed')->count())->toBe(1);
        expect(DB::table('notifications')->where('booking_guid', $booking->guid)->where('type', 'booking.payment_receipt')->count())->toBe(1);
    });
});

/*
 * Phase 4 races: payment deadline versus settlement, sequential attempts, refund claim versus check-in, single provider refund.
 */

/** Puts an approved booking past its 15-minute deadline while its grace window is still open. */
function mtxMysqlPastDeadline(object $booking): void
{
    $deadline = now()->subMinute();
    DB::table('bookings')->where('guid', $booking->guid)->update(['payment_expires_at' => $deadline, 'notification_grace_expires_at' => now()->addMinutes(15)]);
    DB::table('payment_intents')->where('booking_guid', $booking->guid)->update(['expires_at' => $deadline]);
}

it('never expires a booking whose pending attempt settles while the expiry pass races the verified lookup on MySQL', function () {
    withMtxMysql(function (array $connection, string $database): void {
        [, $booking, $attempt] = mtxMysqlSeed('pending', ['next_reconcile_at' => now()->subMinute(), 'instruction_expires_at' => now()->addMinutes(5), 'started_at' => now()->subMinutes(10)]);
        mtxMysqlPastDeadline($booking);
        // Paid 5 minutes ago, i.e. before the deadline that passed one minute ago; only the verification is late.
        $base = ['attempt_guid' => $attempt->guid, 'booking_guid' => $booking->guid, 'provider_state' => 'settlement', 'settled_minutes_ago' => 5];
        $jobs = [$base + ['action' => 'reconcile'], $base + ['action' => 'expire_due'], $base + ['action' => 'expire_due'], $base + ['action' => 'expire_due']];

        $run = runMtxWorkers($jobs, $connection, $database);

        $this->assertSame(4, collect($run['results'])->where('ok', true)->count(), mtxAllOk($run['results']));
        expect(DB::table('payment_attempts')->where('guid', $attempt->guid)->value('status'))->toBe('paid');
        expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBe('confirmed');
        expect(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'expired')->count())->toBe(0);
        expect(DB::table('payment_intents')->where('guid', $attempt->payment_intent_guid)->value('status'))->toBe('paid');
        expect(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'confirmed')->count())->toBe(1);
    });
});

it('resets the payment cycle exactly once when the provider proves the only attempt unpaid while the expiry pass races on MySQL', function () {
    withMtxMysql(function (array $connection, string $database): void {
        [, $booking, $attempt] = mtxMysqlSeed('pending', ['next_reconcile_at' => now()->subMinute(), 'instruction_expires_at' => now()->subMinute()]);
        mtxMysqlPastDeadline($booking);
        $base = ['attempt_guid' => $attempt->guid, 'booking_guid' => $booking->guid, 'provider_state' => 'expire'];
        $jobs = [$base + ['action' => 'reconcile'], $base + ['action' => 'expire_due'], $base + ['action' => 'expire_due'], $base + ['action' => 'expire_due']];

        $run = runMtxWorkers($jobs, $connection, $database);

        $this->assertSame(4, collect($run['results'])->where('ok', true)->count(), mtxAllOk($run['results']));
        // A pass after the proof exists: an expiry pass that ran before the lookup only held the booking for verification.
        app(BookingService::class)->expireDue();
        expect(DB::table('payment_attempts')->where('guid', $attempt->guid)->value('status'))->toBe('expired');
        // Unpaid is not an anomaly: the cycle is reset exactly once and the booking keeps its quota for a new method choice.
        expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBe('awaiting_payment');
        expect(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('reason', 'payment_cycle_reset')->count())->toBe(1);
        expect(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'expired')->count())->toBe(0);
        expect(DB::table('payment_intents')->where('guid', $attempt->payment_intent_guid)->value('status'))->toBe('requires_payment');
    });
});

it('starts the payment cycle exactly once when different keys race to choose the first method on MySQL', function () {
    withMtxMysql(function (array $connection, string $database): void {
        [$owner, $booking] = mtxPayable(startCountdown: false);
        $base = ['action' => 'initiate', 'booking_guid' => $booking->guid, 'user_guid' => $owner->guid];
        $jobs = [$base + ['key' => 'cycle-a'], $base + ['key' => 'cycle-b'], $base + ['key' => 'cycle-c'], $base + ['key' => 'cycle-d']];

        $run = runMtxWorkers($jobs, $connection, $database);

        $row = DB::table('bookings')->where('guid', $booking->guid)->first();
        expect((int) $row->payment_cycle)->toBe(1)->and($row->payment_started_at)->not->toBeNull()
            ->and(DB::table('payment_attempts')->count())->toBe(1)->and($run['provider_calls'])->toBe(1)
            ->and(DB::table('payment_intents')->value('expires_at'))->toBe($row->payment_expires_at)
            ->and(DB::table('payment_attempts')->value('instruction_expires_at'))->toBe($row->payment_expires_at);
    });
});

it('cancels a booking exactly once when the owner cancels in parallel with other cancels and a new method choice on MySQL', function () {
    withMtxMysql(function (array $connection, string $database): void {
        [$owner, $booking, $attempt] = mtxMysqlSeed('pending');
        $base = ['booking_guid' => $booking->guid, 'user_guid' => $owner->guid, 'provider_state' => 'cancel'];
        $jobs = [$base + ['action' => 'cancel_booking'], $base + ['action' => 'cancel_booking'], $base + ['action' => 'cancel_booking'],
            $base + ['action' => 'initiate', 'key' => 'late-method', 'method' => 'qris', 'channel' => 'qris']];

        $run = runMtxWorkers($jobs, $connection, $database);

        expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBe('cancelled')
            ->and(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'cancelled')->count())->toBe(1)
            ->and(DB::table('payment_intents')->where('guid', $attempt->payment_intent_guid)->value('status'))->toBe('cancelled')
            ->and(DB::table('payment_attempts')->where('payment_intent_guid', $attempt->payment_intent_guid)->whereIn('status', ['initiating', 'pending', 'unknown', 'paid'])->count())->toBe(0)
            ->and(DB::table('bookings')->whereIn('status', \App\Support\BookingRules::HELD)->count())->toBe(0)
            ->and($run['calls_in_transaction'])->toBe(0);
        foreach ($run['results'] as $result) {
            if (! $result['ok']) {
                expect($result['class'])->not->toContain('QueryException')->and($result['code'])->not->toBeNull();
            }
        }
    });
});

it('lets a settlement win over a booking cancel that races it on MySQL', function () {
    withMtxMysql(function (array $connection, string $database): void {
        [$owner, $booking, $attempt] = mtxMysqlSeed('pending', ['next_reconcile_at' => now()->subMinute()]);
        $base = ['booking_guid' => $booking->guid, 'attempt_guid' => $attempt->guid, 'user_guid' => $owner->guid, 'provider_state' => 'settlement'];
        $jobs = [$base + ['action' => 'cancel_booking'], $base + ['action' => 'reconcile'], $base + ['action' => 'cancel_booking']];

        $run = runMtxWorkers($jobs, $connection, $database);

        expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBe('confirmed')
            ->and(DB::table('payment_intents')->where('guid', $attempt->payment_intent_guid)->value('status'))->toBe('paid')
            ->and(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'cancelled')->count())->toBe(0)
            ->and(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'confirmed')->count())->toBe(1)
            ->and($run['calls_in_transaction'])->toBe(0);
    });
});

it('reserves exactly one attempt and one Charge when different idempotency keys race on the same booking on MySQL', function () {
    withMtxMysql(function (array $connection, string $database): void {
        [$owner, $booking] = mtxPayable();
        $base = ['action' => 'initiate', 'booking_guid' => $booking->guid, 'user_guid' => $owner->guid];
        $jobs = [$base + ['key' => 'race-a'], $base + ['key' => 'race-b'], $base + ['key' => 'race-c'], $base + ['key' => 'race-d']];

        $run = runMtxWorkers($jobs, $connection, $database);

        $intent = DB::table('payment_intents')->where('booking_guid', $booking->guid)->value('guid');
        expect(DB::table('payment_attempts')->where('payment_intent_guid', $intent)->count())->toBe(1);
        expect($run['provider_calls'])->toBe(1)->and($run['calls_in_transaction'])->toBe(0);
        // Every loser fails with a domain error, never an uncaught database error.
        foreach ($run['results'] as $result) {
            if (! $result['ok']) {
                expect($result['class'])->not->toContain('QueryException')->and($result['code'])->not->toBeNull();
            }
        }
        expect(collect($run['results'])->where('ok', true)->count())->toBeGreaterThanOrEqual(1);
        expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBe('awaiting_payment');
    });
});

it('lets exactly one of a refund claim and a check-in win and never both on MySQL', function () {
    withMtxMysql(function (array $connection, string $database): void {
        $config = ['refund' => ['qris_api_enabled' => true, 'qris_window_days' => 14]];
        mtxConfig($config);
        Queue::fake();
        [$owner, $booking, , $destination] = mtxPaid('qris', 'qris');
        $refund = mtxRefundAsk($owner, $booking);
        $admin = makeUser(2);
        $payload = mtxVisitDay($booking, $destination);
        $base = ['booking_guid' => $booking->guid, 'admin_guid' => $admin->guid, 'now' => now()->toIso8601String(), 'config' => $config];
        $jobs = [$base + ['action' => 'refund_decide', 'refund_guid' => $refund], $base + ['action' => 'check_in', 'payload' => $payload, 'key' => 'ci-1'],
            $base + ['action' => 'check_in', 'payload' => $payload, 'key' => 'ci-2']];

        $run = runMtxWorkers($jobs, $connection, $database);

        $request = DB::table('booking_refund_requests')->where('guid', $refund)->first();
        $checkedIn = DB::table('booking_tickets')->where('booking_guid', $booking->guid)->whereNotNull('checked_in_at')->count();
        if ($checkedIn > 0) {
            // Admission committed first: the refund can neither have claimed the money path nor hold admission.
            expect($request->status)->toBe('needs_review')->and((int) $request->admission_hold)->toBe(0)->and($request->refund_key)->toBeNull();
            expect($checkedIn)->toBe(1);
        } else {
            // The refund claim committed first: admission is held and no ticket was admitted.
            expect($request->status)->toBe('refund_pending')->and((int) $request->admission_hold)->toBe(1)->and($request->refund_key)->not->toBeNull();
            foreach ([1, 2] as $index) {
                expect($run['results'][$index]['ok'])->toBeFalse()->and($run['results'][$index]['code'])->toBeIn(['refund_in_progress', 'ticket_revoked']);
            }
        }
        expect(DB::table('ticket_check_ins')->where('booking_guid', $booking->guid)->count())->toBe($checkedIn);
        test()->travelBack();
    });
});

it('sends one stable-key provider refund and never settles it from the accepted answer when workers race on MySQL', function () {
    withMtxMysql(function (array $connection, string $database): void {
        $config = ['refund' => ['qris_api_enabled' => true, 'qris_window_days' => 14]];
        mtxConfig($config);
        Queue::fake();
        [$owner, $booking] = mtxPaid('qris', 'qris');
        $refund = mtxRefundAsk($owner, $booking);
        mtxRefundDecide(makeUser(2), $booking, $refund)->assertOk()->assertJsonPath('data.status', 'refund_pending');
        $claimedKey = mtxRefundRow($refund)->refund_key;
        $base = ['action' => 'process_refund', 'refund_guid' => $refund, 'now' => now()->toIso8601String(), 'provider_state' => 'pending', 'config' => $config];

        $run = runMtxWorkers(array_fill(0, 5, $base), $connection, $database);

        $this->assertSame(5, collect($run['results'])->where('ok', true)->count(), mtxAllOk($run['results']));
        // The lease lets one worker reach the (fake) provider; none of them calls it from inside a database transaction.
        expect($run['refund_calls'])->toBe(1)->and($run['calls_in_transaction'])->toBe(0);
        $row = mtxRefundRow($refund);
        expect($row->refund_key)->toBe($claimedKey)->and((int) $row->admission_hold)->toBe(1)->and($row->status)->toBe('refund_pending');
        expect(DB::table('booking_refund_requests')->where('booking_guid', $booking->guid)->whereIn('status', ['refunded', 'manual_refunded'])->count())->toBe(0);
        test()->travelBack();
    });
});
