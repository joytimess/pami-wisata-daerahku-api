<?php

use App\Jobs\SendEmailVerificationOtp;
use App\Models\User;
use App\Services\EmailVerificationOtpService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

function otpConcurrent(array $jobs, User $user): array
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'otp-race-'.Str::random(12);
    mkdir($dir);
    $workers = [];
    try {
        DB::beginTransaction();
        User::whereKey($user->guid)->lockForUpdate()->firstOrFail();
        foreach ($jobs as $i => $job) {
            $worker = new Process([PHP_BINARY, __DIR__.'/otp_worker.php', "$dir/ready-$i", "$dir/go", json_encode($job)], base_path());
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

it('serializes verification, resend, and simultaneous resend on MySQL', function (string $scenario) {
    Queue::fake([SendEmailVerificationOtp::class]);
    $user = User::factory()->unverified()->create(['role' => 0]);
    try {
        DB::transaction(fn () => app(EmailVerificationOtpService::class)->issue($user));
        $job = Queue::pushed(SendEmailVerificationOtp::class)->first();
        expect($job)->not->toBeNull();
        DB::table('email_verification_otps')->where('user_guid', $user->guid)->update(['sent_at' => now()->subSeconds(61)]);
        $verify = ['action' => 'verify', 'email' => $user->email, 'otp' => $job->otp];
        $resend = ['action' => 'resend', 'email' => $user->email];
        $jobs = match ($scenario) {
            'verify' => [$verify, $verify],
            'mixed' => [$verify, $resend],
            'resend' => [$resend, $resend],
        };
        $results = otpConcurrent($jobs, $user);
        $row = DB::table('email_verification_otps')->where('user_guid', $user->guid)->first();
        if ($scenario === 'verify') {
            expect(collect($results)->where('ok', true))->toHaveCount(1)
                ->and($user->fresh()->hasVerifiedEmail())->toBeTrue();
        } elseif ($scenario === 'mixed') {
            if ($user->fresh()->hasVerifiedEmail()) {
                expect($row === null || $row->consumed_at !== null)->toBeTrue();
            } else {
                expect($row->issuance_id)->not->toBe($job->issuanceId);
                expect(fn () => app(EmailVerificationOtpService::class)->verify($user->email, $job->otp))->toThrow(ValidationException::class);
            }
        } else {
            expect(DB::table('email_verification_otps')->where('user_guid', $user->guid)->count())->toBe(1)
                ->and($row->issuance_id)->not->toBe($job->issuanceId);
            expect(fn () => app(EmailVerificationOtpService::class)->verify($user->email, $job->otp))->toThrow(ValidationException::class);
        }
    } finally {
        DB::table('email_verification_otps')->where('user_guid', $user->guid)->delete();
        $user->delete();
    }
})->with(['verify', 'mixed', 'resend']);
