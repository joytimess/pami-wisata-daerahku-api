<?php

use App\Models\User;
use App\Notifications\QueuedResetPassword;
use App\Services\AccountService;
use App\Services\AuthService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['api_tokens.secret' => str_repeat('s', 64)]);
});

it('responds generically and throttles forgot requests while storing only a token hash', function () {
    Notification::fake();
    $user = User::factory()->create();
    $registered = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'unknown@example.com'])->assertOk()->assertExactJson($registered->json());
    $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk()->assertExactJson($registered->json());
    Notification::assertSentToTimes($user, QueuedResetPassword::class, 1);
    $notification = Notification::sent($user, QueuedResetPassword::class)->first();
    expect(Hash::check($notification->token, DB::table('password_reset_tokens')->where('email', $user->email)->value('token')))->toBeTrue();
});

it('keeps the generic response when notification dispatch fails', function () {
    $user = User::factory()->create();
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('sensitive failure'));
    Log::shouldReceive('error')->once()->with('Password reset notification dispatch failed.', ['exception_class' => RuntimeException::class]);
    $registered = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'missing@example.com'])->assertOk()->assertExactJson($registered->json());
});

it('resets once and revokes access, refresh, and device registrations', function () {
    $user = User::factory()->create();
    $auth = app(AuthService::class);
    $session = $auth->login(['email' => $user->email, 'password' => 'password']);
    $sid = $auth->authenticate($session['access_token'])[1];
    DB::table('device_tokens')->insert(['guid' => (string) Str::uuid(), 'user_guid' => $user->guid, 'session_guid' => $sid, 'token' => 'device', 'platform' => 'android', 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $token = Password::createToken($user);
    $payload = ['email' => $user->email, 'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'new-password'];
    $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk();
    $this->postJson('/api/v1/auth/reset-password', $payload)->assertUnprocessable();
    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue()
        ->and(DB::table('password_reset_tokens')->where('email', $user->email)->exists())->toBeFalse()
        ->and(DB::table('device_tokens')->where('user_guid', $user->guid)->exists())->toBeFalse();
    expect(fn () => $auth->authenticate($session['access_token']))->toThrow(AuthenticationException::class);
    expect(fn () => $auth->refresh($session['refresh_token']))->toThrow(AuthenticationException::class);
    expect(fn () => $auth->login(['email' => $user->email, 'password' => 'password']))->toThrow(AuthenticationException::class);
    expect($auth->login(['email' => $user->email, 'password' => 'new-password']))->toHaveKey('access_token');
});

it('rejects invalid and expired tokens without changing password or sessions', function () {
    $user = User::factory()->create();
    $auth = app(AuthService::class);
    $session = $auth->login(['email' => $user->email, 'password' => 'password']);
    $token = Password::createToken($user);
    $payload = ['email' => $user->email, 'token' => 'invalid', 'password' => 'new-password', 'password_confirmation' => 'new-password'];
    $invalid = $this->postJson('/api/v1/auth/reset-password', $payload)->assertUnprocessable();
    $this->postJson('/api/v1/auth/reset-password', array_merge($payload, ['email' => 'missing@example.com']))
        ->assertUnprocessable()->assertExactJson($invalid->json());
    DB::table('password_reset_tokens')->where('email', $user->email)->update(['created_at' => now()->subMinutes(61)]);
    $payload['token'] = $token;
    $this->postJson('/api/v1/auth/reset-password', $payload)->assertUnprocessable();
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue()
        ->and($auth->authenticate($session['access_token'])[0]->guid)->toBe($user->guid);
});

it('encrypts queued password reset notification payloads', function () {
    config(['queue.default' => 'database']);
    $user = User::factory()->create();
    $token = 'sensitive-reset-token';
    $user->sendPasswordResetNotification($token);
    $payload = DB::table('jobs')->value('payload');
    expect($payload)->toBeString()->not->toContain($token);
});

it('processes queued notifications and records failures without exposing tokens', function () {
    config(['queue.default' => 'database', 'api_tokens.password_reset_url' => 'https://client.example.org/reset-password']);
    $user = User::factory()->create();
    $user->sendPasswordResetNotification('valid-test-token');
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1]);
    expect(DB::table('jobs')->count())->toBe(0)->and(DB::table('failed_jobs')->count())->toBe(0);

    config(['api_tokens.password_reset_url' => 'http://invalid.example.org']);
    $user->sendPasswordResetNotification('sensitive-failed-token');
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1]);
    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->value('payload'))->not->toContain('sensitive-failed-token');

    config(['api_tokens.password_reset_url' => 'https://client.example.org/reset-password']);
    Artisan::call('queue:retry', ['id' => [DB::table('failed_jobs')->value('uuid')]]);
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1]);
    expect(DB::table('jobs')->count())->toBe(0)->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('rolls back all changes if token consumption fails', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite trigger injection only; MySQL DDL would commit the test transaction.');
    }
    $user = User::factory()->create();
    $auth = app(AuthService::class);
    $session = $auth->login(['email' => $user->email, 'password' => 'password']);
    $token = Password::createToken($user);
    DB::statement("CREATE TRIGGER prevent_reset_delete BEFORE DELETE ON password_reset_tokens BEGIN SELECT RAISE(ABORT, 'injected failure'); END");
    try {
        expect(fn () => app(AccountService::class)->reset(['email' => $user->email, 'token' => $token, 'password' => 'new-password']))->toThrow(QueryException::class);
    } finally {
        DB::statement('DROP TRIGGER prevent_reset_delete');
    }
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue()
        ->and(Password::tokenExists($user, $token))->toBeTrue()
        ->and($auth->authenticate($session['access_token'])[0]->guid)->toBe($user->guid);
});

it('encodes link parameters and rejects unsafe configured URLs', function () {
    $user = User::factory()->make(['email' => 'plus+test@example.com']);
    $notification = new QueuedResetPassword('token+value');
    config(['api_tokens.password_reset_url' => 'https://client.example.org/reset-password']);
    $mail = $notification->toMail($user);
    expect($mail->actionUrl)->toBe('https://client.example.org/reset-password?token=token%2Bvalue&email=plus%2Btest%40example.com');
    foreach (['http://client.example.org/reset-password', 'https://YOUR-HTTPS-CLIENT-DOMAIN/reset-password', 'https://client.example.org/reset?token=x', 'https://client.example.org/reset#fragment'] as $url) {
        config(['api_tokens.password_reset_url' => $url]);
        expect(fn () => $notification->toMail($user))->toThrow(RuntimeException::class);
    }
});
