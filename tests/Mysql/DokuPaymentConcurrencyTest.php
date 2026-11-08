<?php

use App\Services\BookingService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

function withDokuMysql(Closure $test): void
{
    if (! env('MYSQL_CONCURRENCY_TESTS') || config('database.default') !== 'mysql'
        || ! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
        test()->markTestSkipped('Requires isolated MySQL test configuration.');
    }
    $original = config('database.connections.mysql');
    $database = 'pami_doku_'.strtolower(Str::random(12)).'_test';
    DB::statement('CREATE DATABASE `'.$database.'`');
    try {
        config(['database.connections.mysql.database' => $database, 'operations.demo_payments_enabled' => false, 'doku.environment' => 'sandbox']);
        DB::purge('mysql');
        expect(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]))->toBe(0);
        $test($original, $database);
    } finally {
        config(['database.connections.mysql' => $original]);
        DB::purge('mysql');
        if (preg_match('/^pami_doku_[a-z0-9]{12}_test$/D', $database)) {
            DB::statement('DROP DATABASE `'.$database.'`');
        }
    }
}

function runDokuWorkers(array $jobs, array $connection, string $database): array
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database;
    mkdir($directory);
    $workers = [];
    $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database,
        'DB_URL' => '', 'MYSQL_CONCURRENCY_TESTS' => '1', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array',
        'QUEUE_CONNECTION' => 'sync', 'FIREBASE_CREDENTIALS' => '', 'DOKU_ENVIRONMENT' => 'sandbox'];
    foreach (['host', 'port', 'username', 'password'] as $key) {
        $environment['DB_'.strtoupper($key)] = (string) $connection[$key];
    }
    try {
        foreach ($jobs as $index => $job) {
            $worker = new Process([PHP_BINARY, __DIR__.'/doku-payment-worker.php', $directory.'/ready-'.$index,
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

        return ['results' => array_map(fn ($index) => json_decode(file_get_contents($directory.'/result-'.$index), true, flags: JSON_THROW_ON_ERROR), array_keys($jobs)),
            'provider_calls' => is_file($directory.'/calls') ? count(file($directory.'/calls')) : 0];
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

it('reserves one DOKU attempt and performs one provider call under concurrent retries', function () {
    withDokuMysql(function (array $connection, string $database): void {
        [$destination, $date] = seedDestination(capacity: 4, price: 10000);
        $owner = makeUser();
        $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date, 2) + ['idempotency_key' => 'doku-race']);
        app(BookingService::class)->decide(makeUser(2), $booking->guid, true);
        $job = ['action' => 'initiate', 'user_guid' => $owner->guid, 'booking_guid' => $booking->guid,
            'input' => ['idempotency_key' => 'same-key', 'method_code' => 'virtual_account', 'channel' => 'bni']];
        $run = runDokuWorkers([$job, $job, $job], $connection, $database);
        $this->assertSame(3, collect($run['results'])->where('ok', true)->count(), json_encode($run['results'], JSON_THROW_ON_ERROR));
        expect(collect($run['results'])->pluck('data.attempt_guid')->unique())->toHaveCount(1);
        expect($run['provider_calls'])->toBe(1);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertDatabaseHas('payment_attempts', ['status' => 'pending']);
        $attempt = DB::table('payment_attempts')->first();
        $outcome = ['status' => 'paid', 'merchant_reference' => $attempt->merchant_reference, 'provider_reference' => $attempt->provider_reference,
            'amount' => (int) $attempt->amount, 'currency' => 'IDR', 'occurred_at' => now()->toIso8601String(),
            'event_key' => 'capture-'.$attempt->guid, 'payload_hash' => hash('sha256', 'capture-'.$attempt->guid)];
        $capture = ['action' => 'receive', 'outcome' => $outcome];
        $events = runDokuWorkers([$capture, $capture, $capture], $connection, $database);
        $this->assertSame(3, collect($events['results'])->where('ok', true)->count(), json_encode($events['results'], JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('payment_events', 1);
        $this->assertDatabaseHas('payment_events', ['disposition' => 'applied']);
        $this->assertDatabaseHas('bookings', ['guid' => $booking->guid, 'status' => 'confirmed']);
        expect(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'confirmed')->count())->toBe(1);
    });
});

it('preserves a timely verified capture while booking expiry runs concurrently', function () {
    withDokuMysql(function (array $connection, string $database): void {
        [$destination, $date] = seedDestination(capacity: 4, price: 10000);
        $owner = makeUser();
        $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date, 2) + ['idempotency_key' => 'expiry-race']);
        app(BookingService::class)->decide(makeUser(2), $booking->guid, true);
        $create = ['action' => 'initiate', 'user_guid' => $owner->guid, 'booking_guid' => $booking->guid,
            'input' => ['idempotency_key' => 'create', 'method_code' => 'virtual_account', 'channel' => 'bni']];
        $run = runDokuWorkers([$create], $connection, $database);
        expect($run['results'][0]['ok'])->toBeTrue();
        $attempt = DB::table('payment_attempts')->first();
        DB::table('bookings')->where('guid', $booking->guid)->update(['payment_expires_at' => now()->subMinute(), 'notification_grace_expires_at' => now()->addMinutes(14)]);
        DB::table('payment_intents')->where('guid', $attempt->payment_intent_guid)->update(['expires_at' => now()->subMinute()]);
        DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['instruction_expires_at' => now()->subMinute(), 'started_at' => now()->subMinutes(10)]);
        $outcome = ['status' => 'paid', 'merchant_reference' => $attempt->merchant_reference, 'provider_reference' => $attempt->provider_reference,
            'amount' => (int) $attempt->amount, 'currency' => 'IDR', 'occurred_at' => now()->subMinutes(2)->toIso8601String(),
            'event_key' => 'timely-'.$attempt->guid, 'payload_hash' => hash('sha256', 'timely-'.$attempt->guid)];
        $race = runDokuWorkers([['action' => 'receive', 'outcome' => $outcome], ['action' => 'expire']], $connection, $database);
        $this->assertSame(2, collect($race['results'])->where('ok', true)->count(), json_encode($race['results'], JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('bookings', ['guid' => $booking->guid, 'status' => 'confirmed']);
        $this->assertDatabaseHas('payment_intents', ['guid' => $attempt->payment_intent_guid, 'status' => 'paid']);
        expect(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'confirmed')->count())->toBe(1);
    });
});
