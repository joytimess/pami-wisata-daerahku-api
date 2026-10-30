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

function fakeGoogle(array $claims): void
{
    config(['api_tokens.firebase_project_id' => 'test-project']);
    app()->bind(AuthService::class, fn () => new class($claims) extends AuthService
    {
        public function __construct(private array $claims) {}

        protected function verifyFirebaseToken(string $project, string $idToken): array
        {
            if ($idToken !== 'valid') {
                throw new RuntimeException('bad token');
            }

            return $this->claims;
        }
    });
}

function googleClaims(string $email, string $sub = 'sub-1'): array
{
    return ['sub' => $sub, 'email' => $email, 'email_verified' => true, 'name' => 'G', 'firebase' => ['sign_in_provider' => 'google.com']];
}

function linkGoogle(User $user, string $sub): void
{
    DB::table('external_identities')->insert(['guid' => (string) Str::uuid(), 'user_guid' => $user->guid, 'provider' => 'google', 'provider_subject' => $sub, 'created_at' => now(), 'updated_at' => now()]);
}

it('allows Google login for an active role 0 user', function () {
    $user = User::factory()->create(['role' => 0]);
    linkGoogle($user, 'sub-1');
    fakeGoogle(googleClaims($user->email));
    $this->postJson('/api/v1/auth/google', ['id_token' => 'valid'])->assertOk();
    expect(DB::table('refresh_tokens')->where('user_guid', $user->guid)->count())->toBe(1);
});

it('creates a role 0 user for a new Google identity', function () {
    fakeGoogle(googleClaims('new@example.org', 'sub-new'));
    $this->postJson('/api/v1/auth/google', ['id_token' => 'valid', 'role' => 2])->assertOk();
    expect(User::where('email', 'new@example.org')->first()->role)->toBe(0);
});

it('rejects Google login for admin roles with 403 and issues no token', function (int $role) {
    $user = User::factory()->create(['role' => $role]);
    linkGoogle($user, 'sub-1');
    fakeGoogle(googleClaims($user->email));
    $this->postJson('/api/v1/auth/google', ['id_token' => 'valid'])->assertForbidden();
    expect(DB::table('refresh_tokens')->count())->toBe(0);
})->with([1, 2]);

it('rejects Google login for an admin matched only by email with 403', function () {
    $user = User::factory()->create(['role' => 1]);
    fakeGoogle(googleClaims($user->email));
    $this->postJson('/api/v1/auth/google', ['id_token' => 'valid'])->assertForbidden();
    expect(DB::table('refresh_tokens')->count())->toBe(0)->and(DB::table('external_identities')->count())->toBe(0);
});

it('rejects Google login for inactive accounts and invalid tokens', function () {
    $user = User::factory()->create(['role' => 0, 'status' => 'suspended']);
    linkGoogle($user, 'sub-1');
    fakeGoogle(googleClaims($user->email));
    $this->postJson('/api/v1/auth/google', ['id_token' => 'valid'])->assertUnauthorized();
    $this->postJson('/api/v1/auth/google', ['id_token' => 'forged'])->assertUnauthorized();
    expect(DB::table('refresh_tokens')->count())->toBe(0);
});

it('blocks Google login and revokes old tokens once a Google user is promoted', function () {
    $admin = User::factory()->create(['role' => 2]);
    $user = User::factory()->create(['role' => 0]);
    linkGoogle($user, 'sub-1');
    fakeGoogle(googleClaims($user->email));
    $token = $this->postJson('/api/v1/auth/google', ['id_token' => 'valid'])->assertOk()->json('data.access_token') ?? null;
    app(AdminService::class)->update($admin, $user, ['role' => 1]);
    expect(DB::table('refresh_tokens')->where('user_guid', $user->guid)->whereNull('revoked_at')->count())->toBe(0);
    $this->postJson('/api/v1/auth/google', ['id_token' => 'valid'])->assertForbidden();
});

it('keeps email/password login working for admins', function () {
    foreach ([1, 2] as $role) {
        $user = User::factory()->create(['role' => $role]);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    }
});
