<?php

namespace App\Services;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Kreait\Firebase\JWT\IdTokenVerifier;
use Throwable;

class AuthService
{
    private function key(): string
    {
        $key = config('api_tokens.secret');
        if (! is_string($key) || strlen($key) < 32) {
            throw new \RuntimeException('JWT_SECRET must contain at least 32 characters.');
        }

        return $key;
    }

    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
            $user->sendEmailVerificationNotification();

            return $this->newSession($user, $data['device_name'] ?? null);
        }, 3);
    }

    public function login(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $user = User::where('email', $data['email'])->lockForUpdate()->first();
            if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password) || $user->status !== 'active') {
                throw new AuthenticationException('Invalid credentials.');
            }

            return $this->newSession($user, $data['device_name'] ?? null);
        }, 3);
    }

    public function google(array $data): array
    {
        $project = config('api_tokens.firebase_project_id');
        if (! $project) {
            throw new \RuntimeException('FIREBASE_PROJECT_ID is not configured.');
        }
        try {
            $token = IdTokenVerifier::createWithProjectId($project)->verifyIdToken($data['id_token']);
            $claims = $token->payload();
            $firebase = $claims['firebase'] ?? [];
            if (($firebase['sign_in_provider'] ?? null) !== 'google.com' || ! ($claims['email_verified'] ?? false)) {
                throw new AuthenticationException;
            }
            $subject = ($claims['sub'] ?? null);
            $email = ($claims['email'] ?? null);
            if (! is_string($subject) || ! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new AuthenticationException;
            }
        } catch (Throwable $e) {
            throw new AuthenticationException('Invalid Google identity token.');
        }
        $user = DB::transaction(function () use ($subject, $email, $claims) {
            $identity = DB::table('external_identities')->where('provider', 'google')->where('provider_subject', $subject)->first();
            if ($identity) {
                return User::findOrFail($identity->user_guid);
            }
            if (User::where('email', $email)->exists()) {
                throw ValidationException::withMessages(['id_token' => 'Account linking requires proof of ownership.']);
            }
            $user = User::create(['name' => (string) ($claims['name'] ?? $email), 'email' => $email]);
            $user->email_verified_at = now();
            $user->save();
            DB::table('external_identities')->insert(['guid' => (string) Str::uuid(), 'user_guid' => $user->guid, 'provider' => 'google', 'provider_subject' => $subject, 'created_at' => now(), 'updated_at' => now()]);

            return $user;
        }, 3);

        return DB::transaction(function () use ($user, $data) {
            $user = User::whereKey($user->guid)->lockForUpdate()->firstOrFail();
            if ($user->status !== 'active') {
                throw new AuthenticationException('Account unavailable.');
            }

            return $this->newSession($user, $data['device_name'] ?? null);
        }, 3);
    }

    private function newSession(User $user, ?string $device): array
    {
        return $this->issue($user, (string) Str::uuid(), $device);
    }

    private function issue(User $user, string $session, ?string $device): array
    {
        $key = $this->key();
        $raw = bin2hex(random_bytes(32));
        $id = (string) Str::uuid();
        DB::table('refresh_tokens')->insert(['guid' => $id, 'user_guid' => $user->guid, 'token_hash' => hash('sha256', $raw), 'session_guid' => $session, 'device_name' => $device, 'expires_at' => now()->addDays(config('api_tokens.refresh_days')), 'created_at' => now(), 'updated_at' => now()]);
        $now = time();
        $jwt = JWT::encode(['iss' => config('api_tokens.issuer'), 'aud' => config('api_tokens.audience'), 'sub' => $user->guid, 'sid' => $session, 'iat' => $now, 'exp' => $now + config('api_tokens.access_minutes') * 60], $key, 'HS256');

        return ['access_token' => $jwt, 'refresh_token' => $raw, 'user' => $user, 'refresh_guid' => $id];
    }

    public function refresh(string $raw): array
    {
        $hash = hash('sha256', $raw);
        $initial = DB::table('refresh_tokens')->where('token_hash', $hash)->first();
        if (! $initial) {
            throw new AuthenticationException('Invalid refresh token.');
        }
        $result = DB::transaction(function () use ($initial, $hash) {
            $user = User::whereKey($initial->user_guid)->lockForUpdate()->first();
            $old = DB::table('refresh_tokens')->where('token_hash', $hash)->lockForUpdate()->first();
            if (! $user || ! $old) {
                return null;
            }
            if ($old->revoked_at !== null) {
                DB::table('refresh_tokens')->where('session_guid', $old->session_guid)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
                DB::table('device_tokens')->where('user_guid', $user->guid)->where('session_guid', $old->session_guid)->delete();

                return null;
            }
            if ($user->status !== 'active' || now()->greaterThanOrEqualTo($old->expires_at)) {
                return null;
            }
            $new = $this->issue($user, $old->session_guid, $old->device_name);
            DB::table('refresh_tokens')->where('guid', $old->guid)->update(['revoked_at' => now(), 'replaced_by_guid' => $new['refresh_guid'], 'updated_at' => now()]);

            return $new;
        }, 3);
        if (! $result) {
            throw new AuthenticationException('Invalid refresh token.');
        }

        return $result;
    }

    public function authenticate(?string $token): array
    {
        try {
            if (! $token) {
                throw new AuthenticationException;
            }
            $claims = JWT::decode($token, new Key($this->key(), 'HS256'));
            if (($claims->iss ?? null) !== config('api_tokens.issuer') || ($claims->aud ?? null) !== config('api_tokens.audience') || ! is_string($claims->sub ?? null) || ! is_string($claims->sid ?? null) || ! isset($claims->exp)) {
                throw new AuthenticationException;
            }
            $user = User::whereKey($claims->sub)->where('status', 'active')->first();
            if (! $user || ! DB::table('refresh_tokens')->where('user_guid', $user->guid)->where('session_guid', $claims->sid)->whereNull('revoked_at')->where('expires_at', '>', now())->exists()) {
                throw new AuthenticationException;
            }

            return [$user, $claims->sid];
        } catch (Throwable $e) {
            throw new AuthenticationException('Unauthenticated.');
        }
    }

    public function logout(User $user, string $session): void
    {
        DB::transaction(function () use ($user, $session) {
            User::whereKey($user->guid)->lockForUpdate()->firstOrFail();
            DB::table('refresh_tokens')->where('user_guid', $user->guid)->where('session_guid', $session)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
            DB::table('device_tokens')->where('user_guid', $user->guid)->where('session_guid', $session)->delete();
        }, 3);
    }
}
