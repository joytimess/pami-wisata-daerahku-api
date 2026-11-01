<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
}
