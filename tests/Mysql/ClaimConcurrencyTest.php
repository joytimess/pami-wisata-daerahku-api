<?php

use App\Services\ApprovalQueueService;
use App\Services\BookingService;
use App\Services\OperationalDashboardService;
use App\Services\TicketCheckInService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('serializes concurrent claims and same key replays on disposable MySQL', function (bool $sameKey, bool $sameActor) {
    if (! env('MYSQL_CONCURRENCY_TESTS') || config('database.default') !== 'mysql' || ! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
        $this->markTestSkipped('Requires dedicated MySQL test configuration.');
    }
    $original = config('database.connections.mysql');
    $database = 'phase1a_'.strtolower(Str::random(12)).'_test';
    DB::statement('CREATE DATABASE `'.$database.'`');
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database;
    mkdir($directory);
    $workers = [];
    try {
        config(['database.connections.mysql.database' => $database]);
        DB::purge('mysql');
        Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
        [$destination, $date] = seedDestination(capacity: 20, days: 10);
        $owner = makeUser();
        $super = makeUser(2);
        $first = makeUser(1);
        $second = makeUser(1);
        app(TicketCheckInService::class)->assign($super, $destination, [$first->guid, $second->guid]);
        $guid = app(BookingService::class)->create($owner, bookingPayload($destination, $date) + ['idempotency_key' => 'claim-race'])->guid;
        $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database, 'DB_URL' => '', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'];
        foreach (['host', 'port', 'username', 'password'] as $key) {
            $environment['DB_'.strtoupper($key === 'username' ? 'username' : $key)] = (string) $original[$key];
        }
        foreach ([$first, $sameActor ? $first : $second] as $index => $admin) {
            $job = ['action' => 'claim', 'user' => $admin->guid, 'guid' => $guid, 'key' => 'claim-'.($sameKey ? 0 : $index)];
            $worker = new Process([PHP_BINARY, __DIR__.'/worker.php', $directory.'/ready-'.$index, $directory.'/go', json_encode($job), $directory.'/result-'.$index], base_path(), $environment);
            $worker->disableOutput();
            $worker->setTimeout(40);
            $worker->start();
            $workers[] = $worker;
        }
        $deadline = microtime(true) + 20;
        while (count(glob($directory.'/ready-*')) < 2 && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(count(glob($directory.'/ready-*')))->toBe(2);
        touch($directory.'/go');
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue();
        }
        $results = array_map(fn ($index) => json_decode(file_get_contents($directory.'/result-'.$index), true, flags: JSON_THROW_ON_ERROR), [0, 1]);
        expect(collect($results)->where('ok', true))->toHaveCount($sameActor ? 2 : 1);
        expect(collect($results)->where('code', 'claim_owned'))->toHaveCount($sameActor ? 0 : 1);
        foreach (collect($results)->where('ok', true) as $result) {
            expect($result['state'])->toBe('active');
            expect($result['owner_user_guid'])->toBe(DB::table('booking_approval_claims')->where('booking_guid', $guid)->value('claimed_by_user_guid'));
        }
        expect(DB::table('booking_approval_claims')->where('booking_guid', $guid)->whereNotNull('claimed_by_user_guid')->count())->toBe(1);
        expect(DB::table('booking_approval_claim_histories')->where('booking_guid', $guid)->where('event', 'claimed')->count())->toBe(1);
        $winner = $results[0]['ok'] ? $first : $second;
        $key = $results[0]['ok'] ? 'claim-0' : 'claim-1';
        app(ApprovalQueueService::class)->mutate($winner, $guid, 'claim', $key);
        expect(DB::table('booking_approval_claim_histories')->count())->toBe(1);
        $dashboard = app(OperationalDashboardService::class);
        expect($dashboard->get($first, 'overview', [])['metrics']['pending_approvals'])->toBe(1);
        expect($dashboard->get($first, 'approvals', [])['personal']['approval_rate_percent'])->toBeNull();
        expect($dashboard->get($first, 'visitors', [])['metrics']['no_show_count'])->toBeNull();
        expect($dashboard->get($first, 'destinations', [])->total())->toBe(1);
        expect($dashboard->get($first, 'queue', [])->total())->toBe(1);
        expect($dashboard->get($first, 'action-center', [])->total())->toBe(1);
    } finally {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
        config(['database.connections.mysql' => $original]);
        DB::purge('mysql');
        if (preg_match('/^phase1a_[a-z0-9]{12}_test$/D', $database)) {
            DB::statement('DROP DATABASE `'.$database.'`');
        }
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
})->with(['different admins' => [false, false], 'same actor and key' => [true, true], 'same actor different keys' => [false, true]]);
