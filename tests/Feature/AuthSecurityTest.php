<?php

use App\Models\User;
use App\Services\AccountService;
use App\Services\AdminService;
use App\Services\AuthService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['api_tokens.secret' => str_repeat('s', 64)]);
});

it('rotates refresh tokens and revokes the session on reuse', function () {
    $auth = app(AuthService::class);
    $user = User::factory()->create();
    $first = $auth->login(['email' => $user->email, 'password' => 'password']);
    $second = $auth->refresh($first['refresh_token']);
    expect($auth->authenticate($second['access_token'])[0]->guid)->toBe($user->guid);
    try {
        $auth->refresh($first['refresh_token']);
        $this->fail('Reused token accepted');
    } catch (AuthenticationException) {
        expect(fn () => $auth->authenticate($second['access_token']))->toThrow(AuthenticationException::class);
    }
});

it('rejects suspended users and logged out sessions', function () {
    $auth = app(AuthService::class);
    $user = User::factory()->create();
    $tokens = $auth->login(['email' => $user->email, 'password' => 'password']);
    $session = $auth->authenticate($tokens['access_token'])[1];
    $auth->logout($user, $session);
    expect(fn () => $auth->authenticate($tokens['access_token']))->toThrow(AuthenticationException::class);
    $user->status = 'suspended';
    $user->save();
    expect(fn () => $auth->login(['email' => $user->email, 'password' => 'password']))->toThrow(AuthenticationException::class);
});

it('updates a profile phone and rejects privilege escalation at registration', function () {
    $user = User::factory()->create();
    app(AccountService::class)->update($user, ['phone' => '0812345678']);
    expect($user->fresh()->phone)->toBe('0812345678');
    $this->postJson('/api/v1/auth/register', ['name' => 'A', 'email' => 'a@example.org', 'password' => 'password123', 'password_confirmation' => 'password123', 'role' => 2])->assertUnprocessable();
});

it('protects the last active superadmin and revokes sessions when a role changes', function () {
    $admin = User::factory()->create(['role' => 2]);
    $other = User::factory()->create();
    $admins = app(AdminService::class);
    expect(fn () => $admins->update($admin, $admin, ['role' => 1]))->toThrow(ValidationException::class);
    $auth = app(AuthService::class);
    $token = $auth->login(['email' => $other->email, 'password' => 'password']);
    $admins->update($admin, $other, ['role' => 1]);
    expect(fn () => $auth->authenticate($token['access_token']))->toThrow(AuthenticationException::class);
});

it('verifies an email through a signed link without an API token', function () {
    $user = User::factory()->unverified()->create();
    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(15), ['id' => $user->guid, 'hash' => sha1($user->email)]);
    $this->getJson($url)->assertOk();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('rejects an invalid Firebase token', function () {
    config(['api_tokens.firebase_project_id' => 'test-project']);
    $this->postJson('/api/v1/auth/google', ['id_token' => 'invalid'])->assertUnauthorized();
});

it('requires reauthentication to close an account and revokes sessions without deleting the account', function () {
    $user = User::factory()->create();
    $auth = app(AuthService::class);
    $token = $auth->login(['email' => $user->email, 'password' => 'password']);
    $accounts = app(AccountService::class);
    expect(fn () => $accounts->close($user, ['password' => 'wrong']))->toThrow(AuthenticationException::class);
    $accounts->close($user, ['password' => 'password']);
    expect($user->fresh()->status)->toBe('closed');
    expect(fn () => $auth->authenticate($token['access_token']))->toThrow(AuthenticationException::class);
});
