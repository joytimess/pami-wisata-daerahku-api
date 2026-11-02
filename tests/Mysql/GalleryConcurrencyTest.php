<?php

use App\Models\Destination;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function () {
    if (! env('MYSQL_CONCURRENCY_TESTS') || config('database.default') !== 'mysql' || ! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
        $this->markTestSkipped('Requires the dedicated *_test MySQL database.');
    }
    Artisan::call('migrate:fresh', ['--force' => true]);
});

function galleryRace(array $jobs, ?Destination $destination = null): array
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gallery-race-'.Str::random(12);
    mkdir($directory);
    $workers = [];
    try {
        if ($destination) {
            DB::beginTransaction();
            Destination::whereKey($destination->guid)->lockForUpdate()->firstOrFail();
        }
        foreach ($jobs as $index => $job) {
            $worker = new Process([PHP_BINARY, __DIR__.'/gallery_worker.php', "$directory/ready-$index", "$directory/go", json_encode($job)], base_path());
            $worker->setTimeout(40);
            $worker->start();
            $workers[] = $worker;
        }
        $deadline = microtime(true) + 20;
        while (count(glob("$directory/ready-*")) < count($jobs) && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(count(glob("$directory/ready-*")))->toBe(count($jobs));
        expect(collect($workers)->every(fn ($worker) => $worker->isRunning()))->toBeTrue();
        touch("$directory/go");
        if ($destination) {
            usleep(300000);
            expect(collect($workers)->every(fn ($worker) => $worker->isRunning()))->toBeTrue('Both independent workers must wait on the destination lock.');
            DB::commit();
        }

        return array_map(function (Process $worker): array {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());

            return json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        }, $workers);
    } finally {
        if (DB::transactionLevel()) {
            DB::rollBack();
        }
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
        foreach (glob("$directory/*") as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}

function galleryRaceSeed(): array
{
    [$destination] = seedDestination();
    $images = [];
    foreach (range(1, 3) as $sequence) {
        $images[] = $destination->images()->create(['sequence' => $sequence, 'storage_disk' => 'local', 'thumbnail_path' => "race/$sequence-thumbnail.webp", 'display_path' => "race/$sequence-display.webp", 'fullscreen_path' => "race/$sequence-fullscreen.webp", 'width' => 20, 'height' => 10, 'mime_type' => 'image/webp', 'bytes' => 100]);
    }

    return [$destination, $images];
}

function galleryRaceJob(User $actor, Destination $destination, array $images, ?string $key = null): array
{
    return ['actor' => $actor->guid, 'destination' => $destination->guid, 'data' => ['name' => 'Saved by '.$actor->guid, 'gallery_version' => 0, 'gallery_manifest' => array_map(fn ($image) => ['image_guid' => $image->guid], $images), 'operation_key' => $key ?? (string) Str::uuid()]];
}

it('allows one of two real MySQL writers at the same gallery version and rejects the other with 409', function () {
    [$destination, $images] = galleryRaceSeed();
    $first = makeUser(1);
    $second = makeUser(2);
    $jobs = [galleryRaceJob($first, $destination, array_reverse($images)), galleryRaceJob($second, $destination, [$images[1], $images[0], $images[2]])];

    $results = collect(galleryRace($jobs, $destination));

    expect($results->where('ok', true))->toHaveCount(1);
    expect($results->where('code', 'gallery_version_conflict'))->toHaveCount(1);
    expect($destination->fresh()->gallery_version)->toBe(1);
    $winning = $results->firstWhere('ok', true)['data'];
    expect($destination->fresh()->name)->toBe($winning['name']);
    expect($destination->images()->pluck('guid')->all())->toBe(array_column($winning['images'], 'guid'));
    expect($destination->images()->pluck('sequence')->all())->toBe([1, 2, 3]);
    $this->assertDatabaseCount('destination_gallery_operations', 1);
});

it('replays concurrent identical saves once over independent MySQL connections', function () {
    [$destination, $images] = galleryRaceSeed();
    $job = galleryRaceJob(makeUser(1), $destination, array_reverse($images));

    $results = galleryRace([$job, $job], $destination);

    expect($results[0]['ok'])->toBeTrue();
    expect($results[1]['ok'])->toBeTrue();
    expect($results[0]['data'])->toEqual($results[1]['data']);
    expect($destination->fresh()->gallery_version)->toBe(1);
    expect($destination->images()->count())->toBe(3);
    $this->assertDatabaseCount('destination_gallery_operations', 1);
});

it('rejects concurrent reuse of one operation key for different payloads on MySQL', function () {
    [$destination, $images] = galleryRaceSeed();
    $first = galleryRaceJob(makeUser(1), $destination, $images);
    $second = $first;
    $second['data']['name'] = 'Different payload';

    $results = collect(galleryRace([$first, $second], $destination));

    expect($results->where('ok', true))->toHaveCount(1);
    expect($results->where('code', 'gallery_idempotency_conflict'))->toHaveCount(1);
    expect($destination->fresh()->gallery_version)->toBe(1);
    $this->assertDatabaseCount('destination_gallery_operations', 1);
});
