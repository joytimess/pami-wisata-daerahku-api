<?php

use App\Jobs\SendPasswordResetOtp;
use App\Models\User;
use App\Services\PasswordResetOtpService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

function passwordResetConcurrent(array $jobs, User $user, bool $lockRateLimit = false): array
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'password-race-'.Str::random(12);
    mkdir($dir);
    $workers = [];
    try {
        DB::beginTransaction();
        User::whereKey($user->guid)->lockForUpdate()->firstOrFail();
        if ($lockRateLimit) {
            DB::table('password_reset_rate_limits')->insert(['key' => hash('sha256', 'ip|192.0.2.123'), 'window' => intdiv(now()->timestamp, 60), 'attempts' => 0, 'expires_at' => now()->addMinute()->timestamp]);
        }
        foreach ($jobs as $i => $job) {
            $worker = new Process([PHP_BINARY, __DIR__.'/password_reset_worker.php', "$dir/ready-$i", "$dir/go", json_encode($job)], base_path());
            $worker->setTimeout(30);
            $worker->start();
            $workers[] = $worker;
        }
        $deadline = microtime(true) + 20;
        while (count(glob("$dir/ready-*")) < count($jobs) && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(count(glob("$dir/ready-*")))->toBe(count($jobs));
        touch("$dir/go");
        usleep(500000);
        expect(collect($workers)->every(fn ($worker) => $worker->isRunning()))->toBeTrue();
        DB::commit();

        return array_map(function ($worker) {
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
        foreach (glob("$dir/*") as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
}

beforeEach(function () {
    if (! env('MYSQL_CONCURRENCY_TESTS') || config('database.default') !== 'mysql' || ! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
        $this->markTestSkipped('Requires the dedicated *_test MySQL database.');
    }
});

it('serializes reset OTP operations on independent MySQL connections', function (string $scenario) {
    Queue::fake([SendPasswordResetOtp::class]);
    for ($round = 0; $round < 3; $round++) {
        $user = User::factory()->create();
        try {
            $service = app(PasswordResetOtpService::class);
            $service->issue($user->email);
            $job = Queue::pushed(SendPasswordResetOtp::class)->last();
            $verify = ['action' => 'verify', 'email' => $user->email, 'otp' => $job->otp];
            $issue = ['action' => 'issue', 'email' => $user->email];
            $ticket = in_array($scenario, ['reset-reset', 'reset-resend'], true) ? $service->verify($user->email, $job->otp)['reset_ticket'] : null;
            DB::table('password_reset_otps')->where('user_guid', $user->guid)->update(['sent_at' => now()->subSeconds(61)]);
            $reset = ['action' => 'reset', 'payload' => ['email' => $user->email, 'reset_ticket' => $ticket, 'password' => 'new-password']];
            $jobs = match ($scenario) {
                'verify-verify' => [$verify, $verify],
                'verify-resend' => [$verify, $issue],
                'resend-resend' => [$issue, $issue],
                'reset-reset' => [$reset, $reset],
                'reset-resend' => [$reset, $issue],
            };
            $results = passwordResetConcurrent($jobs, $user);
            $row = DB::table('password_reset_otps')->where('user_guid', $user->guid)->first();
            if ($scenario === 'verify-verify' || $scenario === 'reset-reset') {
                expect(collect($results)->where('ok', true))->toHaveCount(1);
            }
            if ($scenario === 'verify-resend') {
                expect($row->issuance_id)->not->toBe($job->issuanceId);
                expect(fn () => $service->verify($user->email, $job->otp))->toThrow(ValidationException::class);
                if ($results[0]['ok']) {
                    expect(fn () => $service->reset(['email' => $user->email, 'reset_ticket' => $results[0]['result']['reset_ticket'], 'password' => 'new-password']))->toThrow(ValidationException::class);
                }
            }
            if ($scenario === 'resend-resend') {
                expect(DB::table('password_reset_otps')->where('user_guid', $user->guid)->count())->toBe(1)
                    ->and($row->issuance_id)->not->toBe($job->issuanceId);
            }
            if ($scenario === 'reset-reset' || $scenario === 'reset-resend') {
                expect(Hash::check('new-password', $user->fresh()->password))->toBe($results[0]['ok'] || ($scenario === 'reset-reset' && $results[1]['ok']));
                expect(fn () => $service->reset($reset['payload']))->toThrow(ValidationException::class);
            }
        } finally {
            DB::table('password_reset_otps')->where('user_guid', $user->guid)->delete();
            $user->delete();
        }
    }
})->with(['verify-verify', 'verify-resend', 'resend-resend', 'reset-reset', 'reset-resend']);

it('admits at most ten simultaneous recovery requests per IP on MySQL', function () {
    $user = User::factory()->create();
    try {
        DB::table('password_reset_rate_limits')->delete();
        $jobs = array_fill(0, 12, ['action' => 'request', 'email' => 'unknown@example.org']);
        $results = passwordResetConcurrent($jobs, $user, true);
        expect(collect($results)->where('status', 200))->toHaveCount(10, json_encode($results))
            ->and(collect($results)->where('status', 429))->toHaveCount(2);
    } finally {
        DB::table('password_reset_rate_limits')->delete();
        $user->delete();
    }
});
