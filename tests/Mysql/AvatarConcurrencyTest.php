<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function () {
    if (! env('MYSQL_CONCURRENCY_TESTS') || config('database.default') !== 'mysql' || ! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
        $this->markTestSkipped('Requires the dedicated *_test MySQL database.');
    }
    Artisan::call('migrate:fresh', ['--force' => true]);
});

function avatarRace(array $jobs, User $user, bool $holdLock): array
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'avatar-race-'.Str::random(12);
    mkdir($directory);
    $workers = [];
    config(['filesystems.disks.local.root' => $directory.'/files']);
    Storage::forgetDisk('local');
    try {
        if ($holdLock) {
            DB::beginTransaction();
            User::whereKey($user->guid)->lockForUpdate()->firstOrFail();
        }
        foreach ($jobs as $index => $kind) {
            $worker = new Process([PHP_BINARY, __DIR__.'/avatar_worker.php', $directory, (string) $index, json_encode(['user' => $user->guid, 'kind' => $kind])], base_path());
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
        if ($holdLock) {
            usleep(300000);
            expect(collect($workers)->every(fn ($worker) => $worker->isRunning()))->toBeTrue('Both independent writers wait on the user lock.');
            DB::commit();
        }
        $results = array_map(function (Process $worker): array {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());

            return json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        }, $workers);
        $results['files'] = Storage::disk('local')->allFiles();

        return $results;
    } finally {
        if (DB::transactionLevel()) {
            DB::rollBack();
        }
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
        Storage::disk('local')->deleteDirectory('avatars');
        if (is_dir($directory.'/files')) {
            rmdir($directory.'/files');
        }
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}

it('permits one real MySQL upload writer and returns version conflict to the other', function () {
    $user = makeUser();
    $results = avatarRace(['upload', 'upload'], $user, true);
    expect(collect([$results[0], $results[1]])->where('ok', true))->toHaveCount(1);
    expect(collect([$results[0], $results[1]])->where('code', 'avatar_version_conflict'))->toHaveCount(1);
    expect($user->fresh()->avatar_version)->toBe(1);
    expect($results['files'])->toBe([$user->fresh()->avatar_custom_path]);
    $this->assertDatabaseCount('avatar_file_cleanups', 1);
});

it('does not let a delayed Google snapshot overwrite a concurrent manual default on MySQL', function () {
    $user = makeUser();
    $results = avatarRace(['snapshot', 'manual'], $user, false);
    expect($results[0]['ok'])->toBeTrue();
    expect($results[1]['ok'])->toBeTrue();
    $current = $user->fresh();
    expect($current->avatar_source)->toBe('default');
    expect($current->avatar_manual_choice)->toBeTrue();
    expect($current->avatar_version)->toBe(1);
    expect($current->google_avatar_snapshot_path)->toBeNull();
    expect($results['files'])->toBe([]);
    $this->assertDatabaseCount('avatar_file_cleanups', 0);
});
