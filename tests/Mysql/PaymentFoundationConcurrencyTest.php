<?php

require_once __DIR__.'/DisposableMysqlGuard.php';

use App\Services\BookingService;
use App\Services\FinancialAnalyticsService;
use App\Support\BookingRules;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** Each case owns a disposable database; the configured database is never migrated. */
function withPaymentFoundationMysql(Closure $test): void
{
    if (! env('MYSQL_CONCURRENCY_TESTS') || config('database.default') !== 'mysql'
        || ! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
        test()->markTestSkipped('Requires dedicated MySQL test configuration.');
    }
    $original = config('database.connections.mysql');
    if (! empty($original['url']) || isset($original['read']) || isset($original['write']) || app()->configurationIsCached()) {
        throw new RuntimeException('Disposable MySQL tests reject URL, replica overrides and cached configuration.');
    }
    assertDisposableMysqlAccount($original);
    $database = 'pami_phase1b_'.strtolower(Str::random(12)).'_test';
    DB::statement('CREATE DATABASE `'.$database.'`');
    try {
        config(['database.connections.mysql.database' => $database]);
        DB::purge('mysql');
        expect(DB::selectOne('SELECT DATABASE() AS db')->db)->toBe($database);
        expect(DB::select('SHOW TABLES'))->toBeEmpty();
        expect(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]))->toBe(0);
        $test($original, $database);
    } finally {
        config(['database.connections.mysql' => $original]);
        DB::purge('mysql');
        if (preg_match('/^pami_phase1b_[a-z0-9]{12}_test$/D', $database)) {
            DB::statement('DROP DATABASE `'.$database.'`');
            expect((int) DB::selectOne('SELECT COUNT(*) AS total FROM information_schema.schemata WHERE schema_name = ?', [$database])->total)->toBe(0);
        }
    }
}

/** Real separate connections start together; result files avoid Windows pipe exhaustion. */
function runPaymentFoundationWorkers(array $jobs, array $connection, string $database): array
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database;
    mkdir($directory);
    $workers = [];
    $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database,
        'DB_URL' => '', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'MYSQL_CONCURRENCY_TESTS' => '1'];
    foreach (['host', 'port', 'username', 'password'] as $key) {
        $environment['DB_'.strtoupper($key)] = (string) $connection[$key];
    }
    try {
        foreach ($jobs as $index => $job) {
            $worker = new Process([PHP_BINARY, __DIR__.'/payment-foundation-worker.php',
                $directory.'/ready-'.$index, $directory.'/go', json_encode($job, JSON_THROW_ON_ERROR),
                $directory.'/result-'.$index], base_path(), $environment);
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

function paymentFoundationBooking(): object
{
    [$destination, $date] = seedDestination(price: 100000);
    $booking = app(BookingService::class)->create(makeUser(), bookingPayload($destination, $date) + ['idempotency_key' => 'payment-foundation']);

    return app(BookingService::class)->decide(makeUser(2), $booking->guid, true);
}

function paymentFoundationIntent(object $booking): array
{
    return ['guid' => (string) Str::uuid(), 'booking_guid' => $booking->guid, 'provider' => 'doku',
        'environment' => 'sandbox', 'currency' => 'IDR', 'amount' => 100000,
        'status' => 'requires_payment', 'merchant_reference' => (string) Str::uuid(),
        'expires_at' => DB::table('bookings')->where('guid', $booking->guid)->value('payment_expires_at'),
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()];
}

it('rolls the additive payment migration down and up without altering booking and approval records', function () {
    withPaymentFoundationMysql(function (array $connection, string $database): void {
        $booking = paymentFoundationBooking();
        $historyCount = DB::table('booking_status_histories')->count();
        $claimCount = DB::table('booking_approval_claims')->count();
        // The reconciliation and refund migrations depend on payment tables, so all four roll back (dependents first) and re-apply together.
        $paths = ['database/migrations/2026_11_09_030710_add_escalation_to_payment_attempts.php', 'database/migrations/2026_11_08_190053_create_booking_refund_requests_table.php', 'database/migrations/2026_11_08_034113_add_reconciliation_to_payment_attempts.php', 'database/migrations/2026_11_05_040716_create_payment_tables.php'];
        $names = ['2026_11_09_030710_add_escalation_to_payment_attempts', '2026_11_08_190053_create_booking_refund_requests_table', '2026_11_08_034113_add_reconciliation_to_payment_attempts', '2026_11_05_040716_create_payment_tables'];
        $reconcileColumns = ['next_reconcile_at', 'last_reconciled_at', 'reconcile_count', 'last_error_code', 'escalated_at', 'escalation_reason'];
        expect(DB::table('migrations')->whereIn('migration', $names)->count())->toBe(4);
        expect(Schema::hasColumns('payment_attempts', $reconcileColumns))->toBeTrue();

        expect(DB::selectOne('SELECT DATABASE() AS db')->db)->toBe($database);
        expect(Artisan::call('migrate:rollback', ['--path' => $paths, '--force' => true, '--no-interaction' => true]))->toBe(0);
        expect(DB::table('migrations')->whereIn('migration', $names)->count())->toBe(0);

        foreach (['booking_refund_request_events', 'booking_refund_requests', 'payment_intents', 'payment_attempts', 'payment_events'] as $table) {
            expect(Schema::hasTable($table))->toBeFalse();
        }
        $this->assertDatabaseHas('bookings', ['guid' => $booking->guid, 'status' => 'awaiting_payment']);
        $this->assertDatabaseCount('booking_status_histories', $historyCount);
        $this->assertDatabaseCount('booking_approval_claims', $claimCount);

        expect(DB::selectOne('SELECT DATABASE() AS db')->db)->toBe($database);
        expect(Artisan::call('migrate', ['--path' => $paths, '--force' => true, '--no-interaction' => true]))->toBe(0);
        expect(DB::table('migrations')->whereIn('migration', $names)->count())->toBe(4);
        expect(Schema::hasColumns('payment_attempts', $reconcileColumns))->toBeTrue();

        foreach (['payment_intents', 'payment_attempts', 'payment_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseHas('bookings', ['guid' => $booking->guid, 'status' => 'awaiting_payment']);
        $this->assertDatabaseCount('booking_status_histories', $historyCount);
        $this->assertDatabaseCount('booking_approval_claims', $claimCount);
    });
});

it('allows only one logical payment intent per booking under concurrent inserts', function () {
    withPaymentFoundationMysql(function (array $connection, string $database): void {
        $booking = paymentFoundationBooking();
        $first = paymentFoundationIntent($booking);
        $second = paymentFoundationIntent($booking);

        $results = collect(runPaymentFoundationWorkers([
            ['action' => 'insert', 'table' => 'payment_intents', 'row' => $first],
            ['action' => 'insert', 'table' => 'payment_intents', 'row' => $second],
        ], $connection, $database));

        expect($results->where('ok', true))->toHaveCount(1);
        expect($results->where('duplicate', true))->toHaveCount(1);
        $this->assertDatabaseCount('payment_intents', 1);
        $this->assertDatabaseHas('bookings', ['guid' => $booking->guid, 'status' => 'awaiting_payment']);
        $this->assertDatabaseCount('payment_events', 0);
    });
});

it('deduplicates a provider event key within its environment under concurrent inserts', function () {
    withPaymentFoundationMysql(function (array $connection, string $database): void {
        $event = ['provider' => 'doku', 'environment' => 'sandbox', 'provider_event_key' => 'same-provider-event',
            'payload_hash' => hash('sha256', 'test fixture'), 'received_at' => now()->toDateTimeString()];

        $results = collect(runPaymentFoundationWorkers([
            ['action' => 'insert', 'table' => 'payment_events', 'row' => $event + ['guid' => (string) Str::uuid()]],
            ['action' => 'insert', 'table' => 'payment_events', 'row' => $event + ['guid' => (string) Str::uuid()]],
        ], $connection, $database));

        expect($results->where('ok', true))->toHaveCount(1);
        expect($results->where('duplicate', true))->toHaveCount(1);
        $this->assertDatabaseCount('payment_events', 1);
        $this->assertDatabaseCount('payment_intents', 0);
        $this->assertDatabaseCount('booking_status_histories', 0);
    });
});

it('prevents two attempts claiming the same provider reference concurrently', function () {
    withPaymentFoundationMysql(function (array $connection, string $database): void {
        $booking = paymentFoundationBooking();
        $intent = paymentFoundationIntent($booking);
        DB::table('payment_intents')->insert($intent);
        $attempt = ['payment_intent_guid' => $intent['guid'], 'provider' => 'doku', 'environment' => 'sandbox',
            'method' => 'checkout', 'provider_reference' => 'same-provider-reference',
            'request_hash' => hash('sha256', 'test fixture'), 'amount' => 100000, 'currency' => 'IDR',
            'started_at' => now()->toDateTimeString(), 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()];
        $jobs = [];
        foreach ([1, 2] as $number) {
            $jobs[] = ['action' => 'insert', 'table' => 'payment_attempts', 'row' => $attempt + [
                'guid' => (string) Str::uuid(), 'attempt_number' => $number,
                'merchant_reference' => (string) Str::uuid(), 'provider_request_id' => (string) Str::uuid()]];
        }

        $results = collect(runPaymentFoundationWorkers($jobs, $connection, $database));

        expect($results->where('ok', true))->toHaveCount(1);
        expect($results->where('duplicate', true))->toHaveCount(1);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertDatabaseHas('payment_intents', ['guid' => $intent['guid'], 'applied_attempt_guid' => null]);
        $this->assertDatabaseHas('bookings', ['guid' => $booking->guid, 'status' => 'awaiting_payment']);
    });
});

it('keeps disabled payment reads side effect free while expiry advances verification', function () {
    withPaymentFoundationMysql(function (array $connection, string $database): void {
        $booking = paymentFoundationBooking();
        $cutoff = now()->subMinutes(5)->toDateTimeString();
        $grace = now()->addMinutes(10)->toDateTimeString();
        DB::table('bookings')->where('guid', $booking->guid)->update([
            'payment_expires_at' => $cutoff, 'notification_grace_expires_at' => $grace]);
        $notifications = DB::table('notifications')->count();

        $results = collect(runPaymentFoundationWorkers([
            ['action' => 'read', 'booking_guid' => $booking->guid, 'user_guid' => $booking->user_guid],
            ['action' => 'expire'],
            ['action' => 'read', 'booking_guid' => $booking->guid, 'user_guid' => $booking->user_guid],
        ], $connection, $database));

        expect($results->where('ok', true))->toHaveCount(3);
        $this->assertDatabaseHas('bookings', ['guid' => $booking->guid, 'status' => 'payment_verification',
            'payment_expires_at' => $cutoff, 'notification_grace_expires_at' => $grace]);
        expect((int) DB::table('bookings')->where('destination_quota_guid', $booking->destination_quota_guid)
            ->whereIn('status', BookingRules::HELD)->sum('quantity'))->toBe(1);
        expect(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)
            ->where('new_status', 'payment_verification')->count())->toBe(1);
        $this->assertDatabaseCount('payment_intents', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('payment_events', 0);
        $this->assertDatabaseCount('notifications', $notifications);
        expect(DB::table('booking_tickets')->whereNotNull('qr_token_hash')->count())->toBe(0);
    });
});

it('separates fixture collected captures from recognized booking revenue on MySQL', function () {
    withPaymentFoundationMysql(function (): void {
        $booking = paymentFoundationBooking();
        $intent = paymentFoundationIntent($booking);
        DB::table('payment_intents')->insert($intent);
        $started = now()->subMinutes(2)->toDateTimeString();
        $paid = now()->subMinute()->toDateTimeString();
        $applied = null;
        foreach ([1 => 'applied', 2 => 'duplicate_capture'] as $number => $disposition) {
            $guid = (string) Str::uuid();
            DB::table('payment_attempts')->insert([
                'guid' => $guid, 'payment_intent_guid' => $intent['guid'], 'provider' => 'doku', 'environment' => 'sandbox',
                'attempt_number' => $number, 'method' => 'checkout', 'merchant_reference' => (string) Str::uuid(),
                'provider_request_id' => (string) Str::uuid(), 'provider_reference' => 'mysql-fixture-'.$number,
                'request_hash' => hash('sha256', 'mysql fixture '.$number), 'amount' => 100000, 'currency' => 'IDR',
                'status' => 'paid', 'started_at' => $started, 'verified_paid_at' => $paid, 'created_at' => $started, 'updated_at' => $paid,
            ]);
            DB::table('payment_events')->insert([
                'guid' => (string) Str::uuid(), 'payment_intent_guid' => $intent['guid'], 'payment_attempt_guid' => $guid,
                'provider' => 'doku', 'environment' => 'sandbox', 'provider_event_key' => 'mysql-fixture-capture-'.$number,
                'payload_hash' => hash('sha256', 'mysql capture fixture '.$number), 'verification' => 'verified',
                'event_type' => 'capture', 'disposition' => $disposition, 'amount' => 100000, 'currency' => 'IDR',
                'provider_occurred_at' => $paid, 'received_at' => $paid,
            ]);
            $applied ??= $guid;
        }
        DB::table('payment_intents')->where('guid', $intent['guid'])->update([
            'status' => 'paid', 'applied_attempt_guid' => $applied, 'verified_paid_at' => $paid]);
        DB::table('bookings')->where('guid', $booking->guid)->update(['status' => 'confirmed']);
        $actor = makeUser(2);

        $summary = app(FinancialAnalyticsService::class)->summary($actor, ['environment' => 'sandbox']);

        expect($summary['availability'])->toBe('partial');
        expect($summary['test_only'])->toBeTrue();
        expect($summary['gross_collected'])->toBe(200000);
        expect($summary['gross_revenue'])->toBe(100000);
        expect($summary['unapplied_collected'])->toBe(100000);
        expect($summary['successful_payment_attempts'])->toBe(2);
        expect($summary['successful_transactions'])->toBe(1);
        expect($summary['tickets_sold'])->toBe(1);
        expect($summary['gateway_fees'])->toBeNull();
        expect($summary['net_revenue'])->toBeNull();
        expect($summary['avg_payment_seconds'])->toBe(60.0);
        $production = app(FinancialAnalyticsService::class)->summary($actor);
        expect($production['availability'])->toBe('unavailable');
        expect($production['gross_collected'])->toBeNull();
    });
});
