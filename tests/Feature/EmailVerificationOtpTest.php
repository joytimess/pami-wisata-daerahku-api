<?php

use App\Jobs\SendEmailVerificationOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['api_tokens.secret' => str_repeat('s', 64)]);
});

function otpRegister(): array
{
    Queue::fake([SendEmailVerificationOtp::class]);
    $response = test()->postJson('/api/v1/auth/register', [
        'name' => 'OTP User', 'email' => 'otp@example.org', 'password' => 'password123', 'password_confirmation' => 'password123',
    ])->assertSuccessful();
    Queue::assertPushed(SendEmailVerificationOtp::class);
    $job = Queue::pushed(SendEmailVerificationOtp::class)->first();

    return [$response, $job, User::where('email', 'otp@example.org')->firstOrFail()];
}

it('registers an unverified user, queues a six digit OTP, and verifies it only once', function () {
    [$response, $job, $user] = otpRegister();
    expect($user->role)->toBe(0)->and($user->email_verified_at)->toBeNull()
        ->and($job->otp)->toMatch('/^\d{6}$/')
        ->and($response->json('data.verification_required'))->toBeTrue()
        ->and($response->getContent())->not->toContain($job->otp)
        ->and(DB::table('email_verification_otps')->where('user_guid', $user->guid)->value('digest'))->not->toBe($job->otp);
    $payload = ['email' => $user->email, 'otp' => $job->otp];
    $this->postJson('/api/v1/auth/email-verification/verify-otp', $payload)->assertOk();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $this->postJson('/api/v1/auth/email-verification/verify-otp', $payload)->assertUnprocessable();
});

it('rejects malformed, missing, expired, and wrong codes without verifying the account', function () {
    [, $job, $user] = otpRegister();
    $route = '/api/v1/auth/email-verification/verify-otp';
    $this->postJson($route, ['email' => $user->email, 'otp' => 123456])->assertUnprocessable();
    $this->postJson($route, ['email' => $user->email, 'otp' => '12345'])->assertUnprocessable();
    $this->postJson($route, ['email' => $user->email])->assertUnprocessable();
    $wrong = $job->otp === '000000' ? '000001' : '000000';
    $this->postJson($route, ['email' => $user->email, 'otp' => $wrong])->assertUnprocessable();
    $this->travel(11)->minutes();
    $this->postJson($route, ['email' => $user->email, 'otp' => $job->otp])->assertUnprocessable();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('blocks an OTP after five failed attempts', function () {
    [, $job, $user] = otpRegister();
    $wrong = $job->otp === '000000' ? '000001' : '000000';
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/email-verification/verify-otp', ['email' => $user->email, 'otp' => $wrong])->assertUnprocessable();
    }
    $this->postJson('/api/v1/auth/email-verification/verify-otp', ['email' => $user->email, 'otp' => $job->otp])->assertUnprocessable();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('does not reveal account existence when resending or verifying invalid codes', function () {
    [, $job, $user] = otpRegister();
    $route = '/api/v1/auth/email-verification/resend-otp';
    $known = $this->postJson($route, ['email' => $user->email])->assertOk();
    $this->postJson($route, ['email' => 'missing@example.org'])->assertOk()->assertExactJson($known->json());
    $route = '/api/v1/auth/email-verification/verify-otp';
    $wrong = $job->otp === '999999' ? '999998' : '999999';
    $unknown = $this->postJson($route, ['email' => 'missing@example.org', 'otp' => $wrong])->assertUnprocessable();
    $this->postJson($route, ['email' => $user->email, 'otp' => $wrong])->assertUnprocessable()->assertExactJson($unknown->json());
});

it('honors resend cooldown and invalidates the old code', function () {
    [, $old, $user] = otpRegister();
    $route = '/api/v1/auth/email-verification/resend-otp';
    $this->postJson($route, ['email' => $user->email])->assertOk();
    Queue::assertPushed(SendEmailVerificationOtp::class, 1);
    $this->travel(61)->seconds();
    $this->postJson($route, ['email' => $user->email])->assertOk();
    Queue::assertPushed(SendEmailVerificationOtp::class, 2);
    $latest = Queue::pushed(SendEmailVerificationOtp::class)->last();
    $this->postJson('/api/v1/auth/email-verification/verify-otp', ['email' => $user->email, 'otp' => $old->otp])->assertUnprocessable();
    $this->postJson('/api/v1/auth/email-verification/verify-otp', ['email' => $user->email, 'otp' => $latest->otp])->assertOk();
});

it('accepts a zero-prefixed code and gates protected routes until verification', function () {
    [$response, , $user] = otpRegister();
    $code = '012345';
    DB::table('email_verification_otps')->where('user_guid', $user->guid)->update([
        'digest' => hash_hmac('sha256', $user->guid.'|'.$user->email.'|verify_email|'.$code, config('app.key')),
    ]);
    $token = $response->json('data.access_token');
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    $this->withToken($token)->getJson('/api/v1/favorites')->assertForbidden();
    $this->postJson('/api/v1/auth/email-verification/verify-otp', ['email' => $user->email, 'otp' => $code])->assertOk();
    $this->withToken($token)->getJson('/api/v1/favorites')->assertOk();
});

it('does not permit a signed legacy link to bypass OTP for a new registration', function () {
    [, , $user] = otpRegister();
    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(15), ['id' => $user->guid, 'hash' => sha1($user->email)]);
    $this->getJson($url)->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('does not send a stale queued OTP after resend or verification', function () {
    [, $old, $user] = otpRegister();
    Notification::fake();
    $this->travel(61)->seconds();
    $this->postJson('/api/v1/auth/email-verification/resend-otp', ['email' => $user->email])->assertOk();
    $new = Queue::pushed(SendEmailVerificationOtp::class)->last();
    $old->handle();
    Notification::assertNothingSent();
    $this->postJson('/api/v1/auth/email-verification/verify-otp', ['email' => $user->email, 'otp' => $new->otp])->assertOk();
    $new->handle();
    Notification::assertNothingSent();
});

it('encrypts the OTP job payload in persistent queue storage', function () {
    config(['queue.default' => 'database']);
    $user = User::factory()->unverified()->create(['role' => 0]);
    SendEmailVerificationOtp::dispatch($user->guid, 'test-issuance', '012345');
    $payload = DB::table('jobs')->value('payload');
    expect($payload)->toBeString()->not->toContain('012345');
});

it('does not dispatch a job or persist an account when the registration transaction rolls back', function () {
    config(['queue.default' => 'database']);
    DB::beginTransaction();
    try {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Rollback', 'email' => 'rollback@example.org', 'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertSuccessful();
        expect(DB::table('jobs')->count())->toBe(0);
    } finally {
        DB::rollBack();
    }
    expect(DB::table('users')->where('email', 'rollback@example.org')->exists())->toBeFalse()
        ->and(DB::table('email_verification_otps')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('fails closed for log mail transport without storing the OTP in failed jobs', function () {
    config(['queue.default' => 'database', 'mail.default' => 'log']);
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Queue', 'email' => 'queue@example.org', 'password' => 'password123', 'password_confirmation' => 'password123',
    ])->assertSuccessful();
    $payload = DB::table('jobs')->value('payload');
    $command = json_decode($payload, true, 512, JSON_THROW_ON_ERROR)['data']['command'];
    $job = unserialize(Crypt::decrypt($command));
    expect($payload)->not->toContain($job->otp);
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1]);
    $failed = DB::table('failed_jobs')->first();
    expect($failed)->not->toBeNull()
        ->and($failed->payload)->not->toContain($job->otp)
        ->and($failed->exception)->not->toContain($job->otp);
});

it('throttles verification by email across IPs and by IP across emails', function () {
    $route = '/api/v1/auth/email-verification/verify-otp';
    for ($i = 1; $i <= 10; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => "192.0.2.$i"])->postJson($route, ['email' => 'same@example.org', 'otp' => '000000'])->assertUnprocessable();
    }
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])->postJson($route, ['email' => 'same@example.org', 'otp' => '000000'])->assertTooManyRequests();
    for ($i = 1; $i <= 10; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->postJson($route, ['email' => "user$i@example.org", 'otp' => '000000'])->assertUnprocessable();
    }
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->postJson($route, ['email' => 'last@example.org', 'otp' => '000000'])->assertTooManyRequests();
});

it('does not issue OTPs to admins or already verified users', function () {
    Queue::fake([SendEmailVerificationOtp::class]);
    foreach ([User::factory()->unverified()->create(['role' => 1]), User::factory()->unverified()->create(['role' => 2]), User::factory()->create(['role' => 0])] as $user) {
        $this->postJson('/api/v1/auth/email-verification/resend-otp', ['email' => $user->email])->assertOk();
    }
    Queue::assertNothingPushed();
});
