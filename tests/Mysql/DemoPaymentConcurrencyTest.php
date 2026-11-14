<?php

require_once __DIR__.'/DisposableMysqlGuard.php';

use App\Services\BookingService;
use App\Services\DemoPaymentService;
use App\Services\FinancialAnalyticsService;
use App\Support\BookingRules;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

function withDemoMysql(Closure $test): void
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
        config(['database.connections.mysql.database' => $database, 'operations.demo_payments_enabled' => true]);
        DB::purge('mysql');
        assertDisposableMysqlAccount(config('database.connections.mysql'), $database);
        expect(DB::select('SHOW TABLES'))->toBeEmpty();
        expect(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]))->toBe(0);
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

function concurrentDemoBooking(): array
{
    [$destination, $date] = seedDestination(capacity: 4, price: 10000);
    $owner = makeUser();
    $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date, 2) + ['idempotency_key' => 'demo-concurrency']);
    $booking = app(BookingService::class)->decide(makeUser(2), $booking->guid, true);
    startPaymentCountdown($booking->guid);
    $booking = app(\App\Services\TicketService::class)->booking($booking->guid);

    return [$owner, $booking];
}

function runDemoWorkers(array $jobs, array $connection, string $database): array
{
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
            $worker = new Process([PHP_BINARY, __DIR__.'/demo-payment-worker.php', $directory.'/ready-'.$index,
                $directory.'/go', json_encode($job, JSON_THROW_ON_ERROR), $directory.'/result-'.$index], base_path(), $environment);
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
        expect(collect($workers)->every(fn ($worker) => $worker->isRunning()))->toBeTrue();
        touch($directory.'/go');
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue();
        }

        return array_map(fn ($index) => json_decode(file_get_contents($directory.'/result-'.$index), true, flags: JSON_THROW_ON_ERROR), array_keys($jobs));
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

it('creates one pending demo attempt and replays the same key under concurrent initiation', function () {
    withDemoMysql(function (array $connection, string $database): void {
        [$owner, $booking] = concurrentDemoBooking();
        $job = ['action' => 'initiate', 'user_guid' => $owner->guid, 'booking_guid' => $booking->guid, 'input' => ['idempotency_key' => 'same-init']];

        $results = collect(runDemoWorkers([$job, $job, $job], $connection, $database));

        expect($results->where('ok', true))->toHaveCount(3);
        expect($results->pluck('data.attempt_guid')->unique())->toHaveCount(1);
        foreach ($results as $result) {
            expect($result['data'])->toEqual($results->first()['data']);
        }
        $this->assertDatabaseCount('payment_intents', 1);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertDatabaseCount('payment_events', 0);
        $this->assertDatabaseHas('bookings', ['guid' => $booking->guid, 'status' => 'awaiting_payment']);
    });
});

it('applies one demo capture history and notification under duplicate concurrent success', function () {
    withDemoMysql(function (array $connection, string $database): void {
        [$owner, $booking] = concurrentDemoBooking();
        $attempt = app(DemoPaymentService::class)->initiate($owner, $booking->guid, ['idempotency_key' => 'init']);
        $notifications = DB::table('notifications')->count();
        $deliveries = DB::table('notification_deliveries')->count();
        $job = ['action' => 'simulate', 'user_guid' => $owner->guid, 'booking_guid' => $booking->guid,
            'input' => ['idempotency_key' => 'same-success', 'attempt_guid' => $attempt['attempt_guid'], 'outcome' => 'success']];

        $results = collect(runDemoWorkers([$job, $job, array_replace_recursive($job, ['input' => ['idempotency_key' => 'another-success']])], $connection, $database));

        expect($results->where('ok', true))->toHaveCount(3, $results->toJson());
        $this->assertDatabaseCount('payment_events', 1);
        $this->assertDatabaseHas('payment_events', ['provider' => 'demo', 'environment' => 'demo', 'event_type' => 'capture', 'amount' => 20000]);
        $this->assertDatabaseHas('bookings', ['guid' => $booking->guid, 'status' => 'confirmed']);
        expect(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'confirmed')->count())->toBe(1);
        $this->assertDatabaseCount('notifications', $notifications + 1);
        $this->assertDatabaseCount('notification_deliveries', $deliveries);
        $finance = app(FinancialAnalyticsService::class)->summary(makeUser(2), ['environment' => 'demo']);
        expect($finance['source'])->toBe('simulated_demo_events');
        expect($finance['gross_collected'])->toBe(20000);
        expect($finance['gross_revenue'])->toBe(20000);
        expect($finance['successful_transactions'])->toBe(1);
        expect($finance['net_revenue'])->toBeNull();
    });
});

it('serializes contradictory demo outcomes without double application or quota loss', function () {
    withDemoMysql(function (array $connection, string $database): void {
        [$owner, $booking] = concurrentDemoBooking();
        $attempt = app(DemoPaymentService::class)->initiate($owner, $booking->guid, ['idempotency_key' => 'init']);
        $jobs = [];
        foreach (['success', 'failed'] as $outcome) {
            $jobs[] = ['action' => 'simulate', 'user_guid' => $owner->guid, 'booking_guid' => $booking->guid,
                'input' => ['idempotency_key' => $outcome, 'attempt_guid' => $attempt['attempt_guid'], 'outcome' => $outcome]];
        }

        $results = collect(runDemoWorkers($jobs, $connection, $database));

        expect($results->where('ok', true))->toHaveCount(1);
        expect($results->where('ok', false)->pluck('code')->all())->toBe(['demo_payment_conflict']);
        $status = DB::table('payment_attempts')->where('guid', $attempt['attempt_guid'])->value('status');
        expect($status)->toBeIn(['paid', 'failed']);
        $this->assertDatabaseHas('bookings', ['guid' => $booking->guid, 'status' => $status === 'paid' ? 'confirmed' : 'awaiting_payment']);
        expect(DB::table('payment_events')->where('event_type', 'capture')->count())->toBe($status === 'paid' ? 1 : 0);
        expect((int) DB::table('bookings')->where('destination_quota_guid', $booking->destination_quota_guid)->whereIn('status', BookingRules::HELD)->sum('quantity'))->toBe(2);
    });
});

it('rejects demo success beyond cutoff while concurrent expiry advances exactly once', function () {
    withDemoMysql(function (array $connection, string $database): void {
        [$owner, $booking] = concurrentDemoBooking();
        $attempt = app(DemoPaymentService::class)->initiate($owner, $booking->guid, ['idempotency_key' => 'init']);
        DB::table('bookings')->where('guid', $booking->guid)->update(['payment_expires_at' => now()->subMinute(), 'notification_grace_expires_at' => now()->addMinutes(14)]);

        $results = collect(runDemoWorkers([
            ['action' => 'simulate', 'user_guid' => $owner->guid, 'booking_guid' => $booking->guid,
                'input' => ['idempotency_key' => 'late', 'attempt_guid' => $attempt['attempt_guid'], 'outcome' => 'success']],
            ['action' => 'expire'], ['action' => 'expire'],
        ], $connection, $database));

        expect($results->where('ok', true))->toHaveCount(2);
        $this->assertDatabaseHas('bookings', ['guid' => $booking->guid, 'status' => 'payment_verification']);
        expect(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'payment_verification')->count())->toBe(1);
        expect(DB::table('payment_events')->where('event_type', 'capture')->count())->toBe(0);
        expect((int) DB::table('bookings')->where('destination_quota_guid', $booking->destination_quota_guid)->whereIn('status', BookingRules::HELD)->sum('quantity'))->toBe(2);
        expect(DB::table('booking_tickets')->whereNotNull('qr_token_hash')->count())->toBe(0);
    });
});

it('preserves approved booking cancellation rules when demo success races cancellation', function () {
    withDemoMysql(function (array $connection, string $database): void {
        [$owner, $booking] = concurrentDemoBooking();
        $attempt = app(DemoPaymentService::class)->initiate($owner, $booking->guid, ['idempotency_key' => 'init']);

        $results = collect(runDemoWorkers([
            ['action' => 'simulate', 'user_guid' => $owner->guid, 'booking_guid' => $booking->guid,
                'input' => ['idempotency_key' => 'success', 'attempt_guid' => $attempt['attempt_guid'], 'outcome' => 'success']],
            ['action' => 'cancel', 'user_guid' => $owner->guid, 'booking_guid' => $booking->guid],
        ], $connection, $database));

        expect($results->where('ok', true))->toHaveCount(1);
        // Cancel either sees the pending DEMO attempt (still verifying) or the already confirmed booking: both are refusals.
        expect($results->where('ok', false)->pluck('code')->first())->toBeIn(['booking_not_cancellable', 'payment_verification_pending']);
        $this->assertDatabaseHas('bookings', ['guid' => $booking->guid, 'status' => 'confirmed']);
        $this->assertDatabaseCount('payment_events', 1);
        expect((int) DB::table('bookings')->where('destination_quota_guid', $booking->destination_quota_guid)->whereIn('status', BookingRules::HELD)->sum('quantity'))->toBe(2);
    });
});
