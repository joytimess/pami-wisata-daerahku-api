<?php

use App\Jobs\SendEmailVerificationOtp;
use App\Jobs\SendPasswordResetOtp;
use App\Models\User;
use App\Services\AuthService;
use App\Services\PasswordResetOtpService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['api_tokens.secret' => str_repeat('s', 64)]);
});

function resetChallenge(?User $user = null): array
{
    Queue::fake([SendPasswordResetOtp::class]);
    $user ??= User::factory()->create();
    test()->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
    Queue::assertPushed(SendPasswordResetOtp::class);

    return [$user, Queue::pushed(SendPasswordResetOtp::class)->last()];
}

function resetTicket(User $user, string $otp): string
{
    $response = test()->postJson('/api/v1/auth/password-reset/verify-otp', ['email' => $user->email, 'otp' => $otp])->assertOk();
    expect($response->json('data.expires_in_seconds'))->toBe(300)
        ->and($response->json('data.reset_ticket'))->toBeString();

    return $response->json('data.reset_ticket');
}

it('acknowledges known and unknown addresses identically and issues protected six digit codes for each role', function () {
    Queue::fake([SendPasswordResetOtp::class]);
    foreach ([0, 1, 2] as $role) {
        $user = User::factory()->create(['role' => $role]);
        $response = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => "unknown$role@example.org"])->assertOk()->assertExactJson($response->json());
        $job = Queue::pushed(SendPasswordResetOtp::class)->last();
        $row = DB::table('password_reset_otps')->where('user_guid', $user->guid)->first();
        expect($job->otp)->toMatch('/^\d{6}$/')->and($row->digest)->not->toContain($job->otp)
            ->and($response->getContent())->not->toContain($job->otp)
            ->and(Carbon::parse($row->expires_at)->diffInSeconds(Carbon::parse($row->sent_at), true))->toEqual(600);
    }
    Queue::assertPushed(SendPasswordResetOtp::class, 3);
});

it('rejects malformed, wrong, expired, consumed and exhausted OTPs', function () {
    [$user, $job] = resetChallenge();
    $route = '/api/v1/auth/password-reset/verify-otp';
    foreach ([123456, '12345', 'abcdef'] as $otp) {
        $this->postJson($route, ['email' => $user->email, 'otp' => $otp])->assertUnprocessable();
    }
    $wrong = $job->otp === '000000' ? '000001' : '000000';
    $unknown = $this->postJson($route, ['email' => 'missing@example.org', 'otp' => $wrong])->assertUnprocessable();
    for ($i = 0; $i < 5; $i++) {
        $this->postJson($route, ['email' => $user->email, 'otp' => $wrong])->assertUnprocessable()->assertExactJson($unknown->json());
    }
    $this->travel(61)->seconds();
    $this->postJson($route, ['email' => $user->email, 'otp' => $job->otp])->assertUnprocessable();
    $this->postJson('/api/v1/auth/password-reset/resend-otp', ['email' => $user->email])->assertOk();
    $new = Queue::pushed(SendPasswordResetOtp::class)->last();
    $this->travel(601)->seconds();
    $this->postJson($route, ['email' => $user->email, 'otp' => $new->otp])->assertUnprocessable();
});

it('enforces cooldown and invalidates previous codes and tickets on resend', function () {
    [$user, $old] = resetChallenge();
    $route = '/api/v1/auth/password-reset/resend-otp';
    $known = $this->postJson($route, ['email' => $user->email])->assertOk();
    $this->postJson($route, ['email' => 'missing@example.org'])->assertOk()->assertExactJson($known->json());
    Queue::assertPushed(SendPasswordResetOtp::class, 1);
    $ticket = resetTicket($user, $old->otp);
    $this->postJson('/api/v1/auth/password-reset/verify-otp', ['email' => $user->email, 'otp' => $old->otp])->assertUnprocessable();
    $this->travel(61)->seconds();
    $this->postJson($route, ['email' => $user->email])->assertOk();
    Queue::assertPushed(SendPasswordResetOtp::class, 2);
    $new = Queue::pushed(SendPasswordResetOtp::class)->last();
    $this->postJson('/api/v1/auth/reset-password', ['email' => $user->email, 'reset_ticket' => $ticket, 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertUnprocessable();
    $this->postJson('/api/v1/auth/password-reset/verify-otp', ['email' => $user->email, 'otp' => $old->otp])->assertUnprocessable();
    resetTicket($user, $new->otp);
});

it('changes password once and revokes access, refresh and device registrations', function () {
    [$user, $job] = resetChallenge();
    $auth = app(AuthService::class);
    $session = $auth->login(['email' => $user->email, 'password' => 'password']);
    $sid = $auth->authenticate($session['access_token'])[1];
    DB::table('device_tokens')->insert(['guid' => (string) Str::uuid(), 'user_guid' => $user->guid, 'session_guid' => $sid, 'token' => 'device', 'platform' => 'android', 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $ticket = resetTicket($user, $job->otp);
    $data = ['email' => $user->email, 'reset_ticket' => $ticket, 'password' => 'new-password', 'password_confirmation' => 'new-password'];
    $this->postJson('/api/v1/auth/reset-password', array_merge($data, ['password_confirmation' => 'different']))->assertUnprocessable();
    $response = $this->postJson('/api/v1/auth/reset-password', $data)->assertOk();
    expect($response->json('data'))->not->toHaveKey('access_token');
    $this->postJson('/api/v1/auth/reset-password', $data)->assertUnprocessable();
    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue()
        ->and(DB::table('device_tokens')->where('user_guid', $user->guid)->exists())->toBeFalse();
    expect(fn () => $auth->authenticate($session['access_token']))->toThrow(AuthenticationException::class);
    expect(fn () => $auth->refresh($session['refresh_token']))->toThrow(AuthenticationException::class);
    expect(fn () => $auth->login(['email' => $user->email, 'password' => 'password']))->toThrow(AuthenticationException::class);
    expect($auth->login(['email' => $user->email, 'password' => 'new-password']))->toHaveKey('access_token');
});

it('rejects expired tickets and former broker reset tokens', function () {
    [$user, $job] = resetChallenge();
    $legacy = Password::createToken($user);
    $this->postJson('/api/v1/auth/reset-password', ['email' => $user->email, 'token' => $legacy, 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertUnprocessable();
    $ticket = resetTicket($user, $job->otp);
    $this->travel(301)->seconds();
    $this->postJson('/api/v1/auth/reset-password', ['email' => $user->email, 'reset_ticket' => $ticket, 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertUnprocessable();
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('does not deliver stale jobs and encrypts queue payloads and failures', function () {
    [$user, $old] = resetChallenge();
    Notification::fake();
    $this->travel(61)->seconds();
    $this->postJson('/api/v1/auth/password-reset/resend-otp', ['email' => $user->email])->assertOk();
    $old->handle();
    Notification::assertNothingSent();
});

it('encrypts queue payloads and rejects insecure transports without leaking OTP in failed jobs', function () {
    config(['queue.default' => 'database', 'mail.default' => 'log']);
    $user = User::factory()->create();
    SendPasswordResetOtp::dispatch($user->guid, 'test-issuance', '012345');
    $payload = DB::table('jobs')->value('payload');
    expect($payload)->not->toContain('012345');
});
it('does not dispatch after rollback and never stores plaintext in queue failures', function () {
    config(['queue.default' => 'database', 'mail.default' => 'log']);
    $user = User::factory()->create();
    DB::beginTransaction();
    try {
        app(PasswordResetOtpService::class)->issue($user->email);
        expect(DB::table('jobs')->count())->toBe(0);
    } finally {
        DB::rollBack();
    }
    expect(DB::table('password_reset_otps')->where('user_guid', $user->guid)->exists())->toBeFalse()
        ->and(DB::table('jobs')->count())->toBe(0);

    app(PasswordResetOtpService::class)->issue($user->email);
    $payload = DB::table('jobs')->value('payload');
    $command = json_decode($payload, true, 512, JSON_THROW_ON_ERROR)['data']['command'];
    $job = unserialize(Crypt::decrypt($command));
    expect($payload)->not->toContain($job->otp);
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1]);
    $failed = DB::table('failed_jobs')->first();
    expect($failed)->not->toBeNull()->and($failed->payload)->not->toContain($job->otp)->and($failed->exception)->not->toContain($job->otp);
});

it('does not accept a registration OTP for a password reset', function () {
    Queue::fake([SendEmailVerificationOtp::class, SendPasswordResetOtp::class]);
    $this->postJson('/api/v1/auth/register', ['name' => 'User', 'email' => 'registration@example.org', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertSuccessful();
    $registration = Queue::pushed(SendEmailVerificationOtp::class)->first();
    Queue::assertPushed(SendEmailVerificationOtp::class);
    $this->postJson('/api/v1/auth/password-reset/verify-otp', ['email' => 'registration@example.org', 'otp' => $registration->otp])->assertUnprocessable();
});
it('rolls back ticket consumption and sessions when changing the password fails', function () {
    [$user, $job] = resetChallenge();
    $auth = app(AuthService::class);
    $session = $auth->login(['email' => $user->email, 'password' => 'password']);
    $ticket = resetTicket($user, $job->otp);
    DB::statement("CREATE TRIGGER fail_password_reset BEFORE UPDATE OF password ON users BEGIN SELECT RAISE(ABORT, 'injected failure'); END");
    try {
        expect(fn () => app(PasswordResetOtpService::class)->reset(['email' => $user->email, 'reset_ticket' => $ticket, 'password' => 'new-password']))->toThrow(QueryException::class);
    } finally {
        DB::statement('DROP TRIGGER fail_password_reset');
    }
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue()
        ->and($auth->authenticate($session['access_token'])[0]->guid)->toBe($user->guid);
    $this->postJson('/api/v1/auth/reset-password', ['email' => $user->email, 'reset_ticket' => $ticket, 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertOk();
});
