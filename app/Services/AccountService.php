<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Kreait\Firebase\JWT\IdTokenVerifier;

class AccountService
{
    public function update(User $user, array $data): User
    {
        $user->fill($data)->save();

        return $user;
    }

    public function verifyLink(string $id, string $hash): void
    {
        DB::transaction(function () use ($id, $hash) {
            $user = User::whereKey($id)->lockForUpdate()->firstOrFail();
            if (! hash_equals(sha1($user->getEmailForVerification()), $hash)
                || DB::table('email_verification_otps')->where('user_guid', $user->guid)->exists()) {
                throw new AuthorizationException;
            }
            $this->verify($user);
        }, 3);
    }

    public function verify(User $user): void
    {
        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }
    }

    public function resend(User $user): void
    {
        if (DB::table('email_verification_otps')->where('user_guid', $user->guid)->exists()) {
            app(EmailVerificationOtpService::class)->resend($user->email);
        } elseif (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }
    }

    public function forgot(string $email): void
    {
        $this->assertPasswordBrokerConnection();
        DB::transaction(function () use ($email) {
            User::where('email', $email)->lockForUpdate()->first();
            Password::sendResetLink(['email' => $email], function (User $user, string $token) {
                DB::afterCommit(function () use ($user, $token) {
                    try {
                        $user->sendPasswordResetNotification($token);
                    } catch (\Throwable $exception) {
                        Log::error('Password reset notification dispatch failed.', ['exception_class' => $exception::class]);
                    }
                });
            });
        }, 3);
    }

    public function close(User $user, array $data): void
    {
        $subject = null;
        if (! empty($data['id_token'])) {
            $project = config('api_tokens.firebase_project_id');
            if (! $project) {
                throw new \RuntimeException('FIREBASE_PROJECT_ID is not configured.');
            }
            try {
                $claims = IdTokenVerifier::createWithProjectId($project)->verifyIdToken($data['id_token'])->payload();
                if (($claims['firebase']['sign_in_provider'] ?? null) !== 'google.com' || ! is_int($claims['auth_time'] ?? null) || $claims['auth_time'] < time() - 300 || $claims['auth_time'] > time()) {
                    throw new \RuntimeException;
                }
                $subject = $claims['sub'] ?? null;
                if (! is_string($subject)) {
                    throw new \RuntimeException;
                }
            } catch (\Throwable $e) {
                throw new AuthenticationException('Recent Google authentication required.');
            }
        }
        DB::transaction(function () use ($user, $data, $subject) {
            $superadmins = User::where('role', 2)->where('status', 'active')->orderBy('guid')->lockForUpdate()->get();
            $user = User::whereKey($user->guid)->lockForUpdate()->firstOrFail();
            $valid = $subject !== null
                ? DB::table('external_identities')->where('user_guid', $user->guid)->where('provider', 'google')->where('provider_subject', $subject)->exists()
                : ($user->password && Hash::check($data['password'] ?? '', $user->password));
            if (! $valid || $user->status !== 'active') {
                throw new AuthenticationException('Reauthentication failed.');
            }
            if ($user->role === 2 && $superadmins->count() <= 1) {
                throw ValidationException::withMessages(['account' => 'The last active superadmin cannot close the account.']);
            }
            $user->status = 'closed';
            $user->save();
            DB::table('refresh_tokens')->where('user_guid', $user->guid)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
            DB::table('device_tokens')->where('user_guid', $user->guid)->delete();
        }, 3);
    }

    public function reset(array $data): void
    {
        $this->assertPasswordBrokerConnection();
        $result = DB::transaction(function () use ($data) {
            User::where('email', $data['email'])->lockForUpdate()->first();

            return Password::reset($data, function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                DB::table('refresh_tokens')->where('user_guid', $user->guid)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
                DB::table('device_tokens')->where('user_guid', $user->guid)->delete();
                DB::afterCommit(fn () => event(new PasswordReset($user)));
            });
        }, 3);
        if ($result !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__(Password::INVALID_TOKEN)]]);
        }
    }

    private function assertPasswordBrokerConnection(): void
    {
        $repository = Password::broker()->getRepository();
        $userConnection = (new User)->getConnection();
        if (! $repository instanceof DatabaseTokenRepository
            || $repository->getConnection() !== $userConnection
            || DB::connection() !== $userConnection) {
            throw new \RuntimeException('Password reset requires a shared database connection.');
        }
    }
}
