<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('consumes a reset token exactly once across concurrent MySQL connections', function () {
    if (! env('MYSQL_CONCURRENCY_TESTS') || config('database.default') !== 'mysql' || ! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
        $this->markTestSkipped('Requires the dedicated *_test MySQL database.');
    }
    for ($round = 0; $round < 3; $round++) {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'password-race-'.Str::random(12);
        mkdir($dir);
        $workers = [];
        try {
            DB::beginTransaction();
            User::whereKey($user->guid)->lockForUpdate()->firstOrFail();
            foreach ([0, 1] as $i) {
                $data = ['email' => $user->email, 'token' => $token, 'password' => "new-password-$i"];
                $workers[$i] = new Process([PHP_BINARY, __DIR__.'/password_reset_worker.php', "$dir/ready-$i", "$dir/go", json_encode($data)], base_path());
                $workers[$i]->setTimeout(30);
                $workers[$i]->start();
            }
            $deadline = microtime(true) + 20;
            while (count(glob("$dir/ready-*")) !== 2 && microtime(true) < $deadline) {
                usleep(10000);
            }
            expect(count(glob("$dir/ready-*")))->toBe(2);
            touch("$dir/go");
            usleep(500000);
            expect($workers[0]->isRunning() && $workers[1]->isRunning())->toBeTrue();
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
                $results[] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }
            expect(collect($results)->where('ok', true))->toHaveCount(1);
            $winner = $results[0]['ok'] ? 0 : 1;
            expect(Hash::check("new-password-$winner", $user->fresh()->password))->toBeTrue()
                ->and(DB::table('password_reset_tokens')->where('email', $user->email)->exists())->toBeFalse();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->delete();
            foreach (glob("$dir/*") as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
});
