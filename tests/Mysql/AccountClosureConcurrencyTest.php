<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('serializes two superadmin closures and keeps one active', function () {
    if (! env('MYSQL_CONCURRENCY_TESTS') || DB::getDriverName() !== 'mysql'
        || DB::getDatabaseName() !== 'pami_p1p2_test') {
        $this->markTestSkipped('Requires the isolated P1/P2 MySQL test schema.');
    }

    expect(User::where('role', 2)->where('status', 'active')->count())->toBe(0);
    $users = User::factory()->count(2)->create(['role' => 2]);
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pami-close-'.Str::random(12);
    mkdir($directory);
    $workers = [];
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'pami_p1p2_test') {
            exit(2);
        }
        [, $guid, $ready, $go] = $argv;
        touch($ready);
        $deadline = microtime(true) + 15;
        while (! file_exists($go) && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (! file_exists($go)) {
            exit(3);
        }
        $user = App\Models\User::findOrFail($guid);
        try {
            $app->make(App\Services\AccountService::class)->close($user, ['password' => 'password']);
            echo 'closed';
        } catch (Illuminate\Validation\ValidationException $exception) {
            echo $exception->response?->getData(true)['code'] ?? 'rejected';
        }
        PHP;

    try {
        DB::beginTransaction();
        User::whereKey($users->min('guid'))->lockForUpdate()->firstOrFail();
        foreach ($users as $index => $user) {
            $worker = new Process([PHP_BINARY, '-r', $script, $user->guid, "$directory/ready-$index", "$directory/go"], base_path(), timeout: 25);
            $worker->start();
            $workers[] = $worker;
        }
        $deadline = microtime(true) + 10;
        while (count(glob("$directory/ready-*")) < 2 && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(glob("$directory/ready-*"))->toHaveCount(2);
        touch("$directory/go");
        usleep(250000);
        expect(collect($workers)->every(fn (Process $worker) => $worker->isRunning()))->toBeTrue();
        DB::commit();

        $results = collect($workers)->map(function (Process $worker): string {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());

            return $worker->getOutput();
        })->sort()->values()->all();
        expect($results)->toBe(['closed', 'last_active_superadmin']);
        expect(User::where('role', 2)->where('status', 'active')->count())->toBe(1);
        expect(User::where('role', 2)->where('status', 'closed')->count())->toBe(1);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($workers as $worker) {
            $worker->stop();
        }
        User::whereKey($users->modelKeys())->delete();
        foreach (glob("$directory/*") as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});
