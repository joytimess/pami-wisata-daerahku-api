<?php

use App\Models\Destination;
use App\Models\User;
use App\Services\BookingService;
use App\Support\BookingRules;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/*
| Real MySQL concurrency tests. Run with: php artisan test -c phpunit.mysql.xml
| Each job runs in its own PHP process against the dedicated `*_test` database, so row locks
| (SELECT ... FOR UPDATE) are exercised for real; SQLite cannot prove any of this.
*/

beforeEach(function () {
    if (! env('MYSQL_CONCURRENCY_TESTS') || ! str_ends_with((string) config('database.connections.mysql.database'), '_test') || config('database.default') !== 'mysql') {
        $this->markTestSkipped('Run with phpunit.mysql.xml against the dedicated *_test MySQL database.');
    }
    Artisan::call('migrate:fresh', ['--force' => true]);
});

/** Starts every job, waits until all have booted, releases them together, returns decoded results. */
function runConcurrently(array $jobs): array
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wisata-conc-'.Str::random(8);
    mkdir($dir);
    $go = $dir.DIRECTORY_SEPARATOR.'go';
    $processes = [];
    foreach ($jobs as $i => $job) {
        $process = new Process([PHP_BINARY, __DIR__.'/worker.php', $dir.DIRECTORY_SEPARATOR."ready-$i", $go, json_encode($job)], base_path());
        $process->setTimeout(120);
        $process->start();
        $processes[$i] = $process;
    }
    $deadline = microtime(true) + 90;
    while (count(glob($dir.DIRECTORY_SEPARATOR.'ready-*')) < count($jobs) && microtime(true) < $deadline) {
        usleep(20000);
    }
    touch($go);
    $results = [];
    foreach ($processes as $i => $process) {
        $process->wait();
        $decoded = json_decode(trim($process->getOutput()), true);
        expect($decoded)->not->toBeNull('worker output: '.$process->getOutput().$process->getErrorOutput());
        $results[$i] = $decoded;
    }
    array_map('unlink', glob($dir.DIRECTORY_SEPARATOR.'*'));
    rmdir($dir);

    return $results;
}

function mysqlSeed(int $capacity, int $price = 0): array
{
    [$destination, $date, $quota] = seedDestination(capacity: $capacity, price: $price);

    return [$destination, $date, $quota];
}

function createJob(User $user, Destination $destination, string $date, int $quantity = 1, ?string $key = null): array
{
    return ['action' => 'create', 'user' => $user->guid, 'key' => $key ?? 'k-'.Str::random(12), 'payload' => bookingPayload($destination, $date, $quantity)];
}

function held(string $quota): int
{
    return (int) DB::table('bookings')->where('destination_quota_guid', $quota)->whereIn('status', BookingRules::HELD)->sum('quantity');
}

it('never oversells when many users book the last seats at the same time', function () {
    [$destination, $date, $quota] = mysqlSeed(10);
    $jobs = collect(range(1, 24))->map(fn () => createJob(makeUser(), $destination, $date))->all();

    $results = collect(runConcurrently($jobs));
    expect($results->where('ok', true))->toHaveCount(10);
    expect($results->where('ok', false)->pluck('code')->unique()->all())->toBe(['insufficient_capacity']);
    expect(held($quota))->toBe(10)->and(DB::table('bookings')->count())->toBe(10);
});

it('respects capacity with mixed quantities under contention', function () {
    [$destination, $date, $quota] = mysqlSeed(20);
    $jobs = collect(range(1, 12))->map(fn () => createJob(makeUser(), $destination, $date, 3))->all();

    $results = collect(runConcurrently($jobs));
    expect($results->where('ok', true))->toHaveCount(6)->and(held($quota))->toBe(18);
    expect($results->where('ok', false)->pluck('code')->unique()->all())->toBe(['insufficient_capacity']);
});

it('creates exactly one booking when the same idempotency key is sent concurrently', function () {
    [$destination, $date, $quota] = mysqlSeed(10);
    $user = makeUser();
    $job = createJob($user, $destination, $date, 2, 'same-key');

    $results = collect(runConcurrently(array_fill(0, 8, $job)));
    expect($results->where('ok', true))->toHaveCount(8)->and($results->pluck('guid')->unique())->toHaveCount(1);
    expect(DB::table('bookings')->count())->toBe(1)->and(held($quota))->toBe(2);
    expect(DB::table('idempotency_records')->count())->toBe(1);
});

it('releases capacity exactly once when a booking is cancelled concurrently', function () {
    [$destination, $date, $quota] = mysqlSeed(10);
    $user = makeUser();
    $booking = app(BookingService::class)->create($user, createJob($user, $destination, $date, 5)['payload'] + ['idempotency_key' => 'seed']);
    expect(held($quota))->toBe(5);

    $results = collect(runConcurrently(array_fill(0, 6, ['action' => 'cancel', 'user' => $user->guid, 'guid' => $booking->guid])));
    expect($results->where('ok', true))->toHaveCount(1);
    expect($results->where('ok', false)->pluck('code')->unique()->all())->toBe(['booking_already_cancelled']);
    expect(held($quota))->toBe(0);
    expect(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'cancelled')->count())->toBe(1);
});

it('lets exactly one of approve, reject and cancel win a race on the same booking', function () {
    [$destination, $date, $quota] = mysqlSeed(40);
    $service = app(BookingService::class);
    $admin = makeUser(1);
    $admin2 = makeUser(2);
    $rounds = [];
    foreach (range(1, 4) as $i) {
        $owner = makeUser();
        $booking = $service->create($owner, createJob($owner, $destination, $date, 2)['payload'] + ['idempotency_key' => "race-$i"]);
        $rounds[] = [$owner, $booking->guid];
    }
    $jobs = [];
    foreach ($rounds as [$owner, $guid]) {
        $jobs[] = ['action' => 'decide', 'user' => $admin->guid, 'guid' => $guid, 'approve' => true];
        $jobs[] = ['action' => 'decide', 'user' => $admin2->guid, 'guid' => $guid, 'approve' => false];
        $jobs[] = ['action' => 'cancel', 'user' => $owner->guid, 'guid' => $guid];
    }

    $results = runConcurrently($jobs);
    foreach ($rounds as $index => [$owner, $guid]) {
        $slice = collect(array_slice($results, $index * 3, 3));
        expect($slice->where('ok', true))->toHaveCount(1);
        $final = DB::table('bookings')->where('guid', $guid)->value('status');
        expect($final)->toBe($slice->firstWhere('ok', true)['status']);
        expect(DB::table('booking_status_histories')->where('booking_guid', $guid)->count())->toBe(2);
        expect($slice->where('ok', false)->pluck('code')->unique()->all())->each->toBeIn(['booking_not_pending', 'booking_already_cancelled', 'booking_not_cancellable']);
    }
    // Capacity matches whatever the winners decided: only confirmed bookings (free destination) still hold seats.
    expect(held($quota))->toBe(2 * DB::table('bookings')->where('status', 'confirmed')->count());
});

it('never lets a quota decrease dip below concurrent allocations', function () {
    [$destination, $date, $quota] = mysqlSeed(10);
    $admin = makeUser(1);
    $jobs = collect(range(1, 10))->map(fn () => createJob(makeUser(), $destination, $date))->all();
    foreach ([6, 5, 4] as $capacity) {
        $jobs[] = ['action' => 'quota', 'user' => $admin->guid, 'destination' => $destination->guid, 'fields' => [
            'visit_date' => $date, 'capacity' => $capacity,
            'booking_opens_at' => now()->subDay()->toIso8601String(), 'booking_closes_at' => now()->addDay()->toIso8601String(),
        ]];
    }

    runConcurrently($jobs);
    $capacity = (int) DB::table('destination_quotas')->where('guid', $quota)->value('capacity');
    expect(held($quota))->toBeLessThanOrEqual($capacity);
});

it('serves a freed seat to exactly one competitor after a cancellation', function () {
    [$destination, $date, $quota] = mysqlSeed(1);
    $owner = makeUser();
    $booking = app(BookingService::class)->create($owner, createJob($owner, $destination, $date)['payload'] + ['idempotency_key' => 'seed']);
    $jobs = [['action' => 'cancel', 'user' => $owner->guid, 'guid' => $booking->guid]];
    foreach (range(1, 5) as $i) {
        $jobs[] = createJob(makeUser(), $destination, $date);
    }

    $results = collect(runConcurrently($jobs));
    expect(held($quota))->toBeLessThanOrEqual(1);
    expect($results->slice(1)->where('ok', true)->count())->toBeLessThanOrEqual(1);
});
