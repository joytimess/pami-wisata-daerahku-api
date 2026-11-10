<?php

require_once __DIR__.'/DisposableMysqlGuard.php';

use App\Services\BookingRefundService;
use App\Services\BookingService;
use App\Services\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

function withDokuMysql(Closure $test): void
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
        config(['database.connections.mysql.database' => $database, 'operations.demo_payments_enabled' => false, 'doku.environment' => 'sandbox']);
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
        $this->assertDatabaseCount('payment_intents', 1);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseHas('payment_attempts', ['status' => 'pending']);
        $attempt = DB::table('payment_attempts')->first();
        expect(CarbonImmutable::parse($attempt->instruction_expires_at)->diffInSeconds(CarbonImmutable::parse($attempt->started_at), true))->toBe(1800.0);
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

it('handles verified captures around the exact attempt deadline while booking expiry runs concurrently', function (int $offset, string $expected) {
    withDokuMysql(function (array $connection, string $database) use ($offset, $expected): void {
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
        $deadline = DB::table('payment_attempts')->where('guid', $attempt->guid)->value('instruction_expires_at');
        $outcome = ['status' => 'paid', 'merchant_reference' => $attempt->merchant_reference, 'provider_reference' => $attempt->provider_reference,
            'amount' => (int) $attempt->amount, 'currency' => 'IDR', 'occurred_at' => CarbonImmutable::parse($deadline, 'UTC')->addSeconds($offset)->toIso8601String(),
            'event_key' => 'timely-'.$attempt->guid, 'payload_hash' => hash('sha256', 'timely-'.$attempt->guid)];
        $race = runDokuWorkers([['action' => 'receive', 'outcome' => $outcome], ['action' => 'expire']], $connection, $database);
        $this->assertSame(2, collect($race['results'])->where('ok', true)->count(), json_encode($race['results'], JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('bookings', ['guid' => $booking->guid, 'status' => $expected === 'paid' ? 'confirmed' : 'payment_verification']);
        $this->assertDatabaseHas('payment_intents', ['guid' => $attempt->payment_intent_guid, 'status' => $expected]);
        $this->assertDatabaseHas('payment_attempts', ['guid' => $attempt->guid, 'status' => 'paid']);
        $this->assertDatabaseHas('payment_events', ['payment_attempt_guid' => $attempt->guid, 'disposition' => $expected === 'paid' ? 'applied' : 'unapplied']);
        $this->assertDatabaseCount('payment_events', 1);
        $this->assertDatabaseCount('invoices', 1);
        expect(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'confirmed')->count())->toBe($expected === 'paid' ? 1 : 0);
    });
})->with(['timely capture in grace' => [-60, 'paid'], 'exact expiry' => [0, 'needs_review'], 'after expiry' => [1, 'needs_review']]);

it('collapses different create keys and blocks switching an expired unknown attempt on MySQL', function () {
    withDokuMysql(function (array $connection, string $database): void {
        [$destination, $date] = seedDestination(capacity: 4, price: 10000);
        $owner = makeUser();
        $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date, 2) + ['idempotency_key' => 'different-keys']);
        app(BookingService::class)->decide(makeUser(2), $booking->guid, true);
        $job = ['action' => 'initiate', 'user_guid' => $owner->guid, 'booking_guid' => $booking->guid,
            'input' => ['idempotency_key' => 'first', 'method_code' => 'virtual_account', 'channel' => 'bni']];
        $second = array_replace_recursive($job, ['input' => ['idempotency_key' => 'second']]);
        $third = array_replace_recursive($job, ['input' => ['idempotency_key' => 'third']]);

        $run = runDokuWorkers([$job, $second, $third], $connection, $database);

        $this->assertSame(3, collect($run['results'])->where('ok', true)->count(), json_encode($run['results'], JSON_THROW_ON_ERROR));
        expect(collect($run['results'])->pluck('data.attempt_guid')->unique())->toHaveCount(1);
        expect($run['provider_calls'])->toBe(1);
        $attempt = DB::table('payment_attempts')->first();
        $expiredAt = now()->subMinute()->toDateTimeString();
        DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['status' => 'unknown', 'instruction_expires_at' => $expiredAt]);
        $retry = array_replace_recursive($job, ['input' => ['idempotency_key' => 'retry']]);
        $switch = array_replace_recursive($job, ['input' => ['idempotency_key' => 'switch', 'method_code' => 'qris', 'channel' => 'qris']]);

        $recovery = runDokuWorkers([$retry, $switch], $connection, $database);

        $this->assertSame(2, collect($recovery['results'])->where('ok', true)->count(), json_encode($recovery['results'], JSON_THROW_ON_ERROR));
        expect(collect($recovery['results'])->pluck('data.attempt_guid')->unique()->all())->toBe([$attempt->guid]);
        expect($recovery['provider_calls'])->toBe(0);
        $this->assertDatabaseHas('payment_attempts', ['guid' => $attempt->guid, 'status' => 'unknown', 'instruction_expires_at' => $expiredAt]);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertDatabaseCount('payment_intents', 1);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('payment_events', 0);
    });
});

function dokuPaidViaWorkers(array $connection, string $database, string $key): array
{
    [$destination, $date] = seedDestination(capacity: 4, price: 10000);
    $owner = makeUser();
    $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date, 2) + ['idempotency_key' => $key]);
    app(BookingService::class)->decide(makeUser(2), $booking->guid, true);
    $create = ['action' => 'initiate', 'user_guid' => $owner->guid, 'booking_guid' => $booking->guid,
        'input' => ['idempotency_key' => 'create', 'method_code' => 'virtual_account', 'channel' => 'bni']];
    expect(runDokuWorkers([$create], $connection, $database)['results'][0]['ok'])->toBeTrue();
    $attempt = DB::table('payment_attempts')->first();
    $outcome = ['status' => 'paid', 'merchant_reference' => $attempt->merchant_reference, 'provider_reference' => $attempt->provider_reference,
        'amount' => (int) $attempt->amount, 'currency' => 'IDR', 'occurred_at' => now()->toIso8601String(),
        'event_key' => 'capture-'.$attempt->guid, 'payload_hash' => hash('sha256', 'capture-'.$attempt->guid)];

    return [$owner, $booking, $destination, ['action' => 'receive', 'outcome' => $outcome]];
}

it('issues exactly quantity QR units and one ticket event while capture and scheduler issuance race on MySQL', function () {
    withDokuMysql(function (array $connection, string $database): void {
        [, $booking, , $capture] = dokuPaidViaWorkers($connection, $database, 'ticket-race');
        $issue = ['action' => 'issue_tickets', 'booking_guid' => $booking->guid];

        $run = runDokuWorkers([$capture, $issue, $issue, $issue, $capture], $connection, $database);

        $this->assertSame(5, collect($run['results'])->where('ok', true)->count(), json_encode($run['results'], JSON_THROW_ON_ERROR));
        $tokens = DB::table('booking_tickets')->where('booking_guid', $booking->guid)->orderBy('sequence')->pluck('qr_token_hash')->all();
        expect($tokens)->toHaveCount(2)->and(array_filter($tokens))->toHaveCount(2)->and(array_unique($tokens))->toHaveCount(2);
        expect(DB::table('notifications')->where('booking_guid', $booking->guid)->where('type', 'booking.ticket_issued')->count())->toBe(1);
        expect(DB::table('notifications')->where('booking_guid', $booking->guid)->where('type', 'booking.payment_receipt')->count())->toBe(1);
        expect(DB::table('notification_deliveries')->join('notifications', 'notifications.guid', '=', 'notification_deliveries.notification_guid')->where('notification_deliveries.channel', 'email')
            ->whereIn('notifications.type', ['booking.ticket_issued', 'booking.payment_receipt'])->count())->toBe(2);
        app(TicketService::class)->issue($booking->guid);
        expect(DB::table('booking_tickets')->where('booking_guid', $booking->guid)->orderBy('sequence')->pluck('qr_token_hash')->all())->toBe($tokens);
        expect(DB::table('payment_events')->where('disposition', 'applied')->count())->toBe(1);
    });
});

it('keeps a single open refund request and a single decision under concurrent calls on MySQL', function () {
    withDokuMysql(function (array $connection, string $database): void {
        [$owner, $booking, $destination, $capture] = dokuPaidViaWorkers($connection, $database, 'refund-race');
        expect(runDokuWorkers([$capture], $connection, $database)['results'][0]['ok'])->toBeTrue();
        $request = fn (string $key) => ['action' => 'refund_request', 'user_guid' => $owner->guid, 'booking_guid' => $booking->guid,
            'input' => ['idempotency_key' => $key, 'reason' => 'Perubahan rencana perjalanan']];

        $run = runDokuWorkers([$request('a'), $request('b'), $request('c'), $request('a')], $connection, $database);

        expect(collect($run['results'])->where('ok', true)->count())->toBeGreaterThanOrEqual(1);
        expect(collect($run['results'])->where('ok', false)->pluck('code')->unique()->diff(['refund_already_requested'])->all())->toBe([]);
        expect(DB::table('booking_refund_requests')->where('booking_guid', $booking->guid)->count())->toBe(1);
        $refund = DB::table('booking_refund_requests')->where('booking_guid', $booking->guid)->first();
        expect($refund->active)->toBe(1);
        $admin = makeUser(1);
        assignTestDestination($admin, $destination);
        $decide = fn (string $decision) => ['action' => 'refund_decide', 'user_guid' => $admin->guid, 'booking_guid' => $booking->guid,
            'refund_guid' => $refund->guid, 'input' => ['decision' => $decision, 'note' => 'Catatan']];

        $decisions = runDokuWorkers([$decide('approve'), $decide('approve'), $decide('reject')], $connection, $database);

        expect(collect($decisions['results'])->where('ok', true)->count())->toBe(1);
        expect(collect($decisions['results'])->where('ok', false)->pluck('code')->unique()->all())->toBe(['refund_not_pending']);
        $final = DB::table('booking_refund_requests')->where('guid', $refund->guid)->value('status');
        expect($final)->toBeIn(['manual_action_required', 'rejected']);
        expect(DB::table('booking_refund_request_events')->where('refund_request_guid', $refund->guid)->count())->toBe($final === 'rejected' ? 2 : 3);
        expect(DB::table('bookings')->where('guid', $booking->guid)->value('status'))->toBe('confirmed');
    });
});

it('settles a manual refund exactly once under concurrent calls and revokes unused QR on MySQL', function () {
    withDokuMysql(function (array $connection, string $database): void {
        [$owner, $booking, $destination, $capture] = dokuPaidViaWorkers($connection, $database, 'settle-race');
        expect(runDokuWorkers([$capture], $connection, $database)['results'][0]['ok'])->toBeTrue();
        app(TicketService::class)->issue($booking->guid);
        $admin = makeUser(1);
        assignTestDestination($admin, $destination);
        $refund = app(BookingRefundService::class)->request($owner, $booking->guid, ['idempotency_key' => 'settle', 'reason' => 'Perubahan rencana perjalanan'])['guid'];
        app(BookingRefundService::class)->decide($admin, $booking->guid, $refund, ['decision' => 'approve']);
        $amount = (int) DB::table('booking_refund_requests')->where('guid', $refund)->value('amount');
        $settle = fn (string $reference) => ['action' => 'refund_settle', 'user_guid' => $admin->guid, 'booking_guid' => $booking->guid, 'refund_guid' => $refund,
            'input' => ['settlement_method' => 'bank_transfer', 'settlement_reference' => $reference, 'settlement_amount' => $amount,
                'settled_at' => now()->toIso8601String(), 'evidence_note' => 'Bukti transfer diperiksa dan cocok']];
        $references = ['TRX-RACE-0001', 'TRX-RACE-0001', 'TRX-RACE-0002'];

        $run = runDokuWorkers(array_map($settle, $references), $connection, $database);

        $row = DB::table('booking_refund_requests')->where('guid', $refund)->first();
        expect($row->status)->toBe('manual_refunded')->and($row->active)->toBeNull()->and($row->refund_channel)->toBe('manual');
        // Exactly one reference won the race. A retry carrying that same reference is an idempotent acknowledgement (ok), never a second
        // settlement; every call with a different reference is rejected. Acknowledgements and financial effects are asserted separately below.
        $winningReference = $row->settlement_reference;
        expect($winningReference)->toBeIn(['TRX-RACE-0001', 'TRX-RACE-0002']);
        foreach ($run['results'] as $index => $result) {
            if ($references[$index] === $winningReference) {
                expect($result['ok'])->toBeTrue();
            } else {
                expect($result)->toMatchArray(['ok' => false, 'code' => 'refund_not_settleable']);
            }
        }
        expect(collect($run['results'])->where('ok', true)->count())->toBe(count(array_keys($references, $winningReference, true)));
        expect(DB::table('booking_refund_request_events')->where('refund_request_guid', $refund)->where('to_status', 'manual_refunded')->count())->toBe(1);
        expect(DB::table('booking_tickets')->where('booking_guid', $booking->guid)->whereNotNull('qr_token_hash')->whereNull('qr_revoked_at')->count())->toBe(0);
        expect(DB::table('booking_refund_requests')->whereNotNull('settlement_reference')->count())->toBe(1);
    });
});
