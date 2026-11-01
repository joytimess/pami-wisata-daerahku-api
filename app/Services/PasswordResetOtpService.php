<?php

namespace App\Services;

use App\Jobs\SendPasswordResetOtp;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetOtpService
{
    public function issue(string $email): void
    {
        $this->assertConnection();
        $email = strtolower(trim($email));
        DB::transaction(function () use ($email) {
            $user = User::where('email', $email)->lockForUpdate()->first();
            if (! $user) {
                return;
            }
            $previous = DB::table('password_reset_otps')->where('user_guid', $user->guid)->lockForUpdate()->first();
            if ($previous && now()->lt(Carbon::parse($previous->sent_at)->addSeconds(60))) {
                return;
            }
            $issuance = (string) Str::uuid();
            do {
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            } while ($previous && hash_equals($previous->digest, $this->digest($user->guid, $previous->email, $previous->issuance_id, $code)));
            DB::table('password_reset_otps')->updateOrInsert(['user_guid' => $user->guid], [
                'email' => strtolower(trim($user->email)), 'issuance_id' => $issuance,
                'digest' => $this->digest($user->guid, strtolower(trim($user->email)), $issuance, $code),
                'expires_at' => now()->addMinutes(10), 'sent_at' => now(), 'attempts' => 0,
                'consumed_at' => null, 'ticket_hash' => null, 'ticket_expires_at' => null,
            ]);
            DB::afterCommit(function () use ($user, $issuance, $code) {
                try {
                    SendPasswordResetOtp::dispatch($user->guid, $issuance, $code)->onConnection('database');
                } catch (\Throwable $exception) {
                    Log::error('Password reset OTP dispatch failed.', ['exception_class' => $exception::class]);
                }
            });
        }, 3);
    }

    public function verify(string $email, string $otp): array
    {
        $this->assertConnection();
        $email = strtolower(trim($email));
        $ticket = DB::transaction(function () use ($email, $otp) {
            $user = User::where('email', $email)->lockForUpdate()->first();
            if (! $user) {
                return null;
            }
            $row = DB::table('password_reset_otps')->where('user_guid', $user->guid)->lockForUpdate()->first();
            if (! $row || $row->email !== strtolower(trim($user->email)) || $row->consumed_at || $row->attempts >= 5 || now()->gte(Carbon::parse($row->expires_at))) {
                return null;
            }
            if (! hash_equals($row->digest, $this->digest($user->guid, $row->email, $row->issuance_id, $otp))) {
                DB::table('password_reset_otps')->where('user_guid', $user->guid)->increment('attempts');

                return null;
            }
            $ticket = bin2hex(random_bytes(32));
            DB::table('password_reset_otps')->where('user_guid', $user->guid)->update([
                'consumed_at' => now(), 'ticket_hash' => hash('sha256', $ticket), 'ticket_expires_at' => now()->addMinutes(5),
            ]);

            return $ticket;
        }, 3);
        if ($ticket === null) {
            throw ValidationException::withMessages(['otp' => ['The verification code is invalid or expired.']]);
        }

        return ['reset_ticket' => $ticket, 'expires_in_seconds' => 300];
    }

    public function reset(array $data): void
    {
        $this->assertConnection();
        $email = strtolower(trim($data['email']));
        $success = DB::transaction(function () use ($email, $data) {
            $user = User::where('email', $email)->lockForUpdate()->first();
            if (! $user) {
                return false;
            }
            $row = DB::table('password_reset_otps')->where('user_guid', $user->guid)->lockForUpdate()->first();
            if (! $row || $row->email !== strtolower(trim($user->email)) || ! $row->ticket_hash || ! $row->ticket_expires_at || now()->gte(Carbon::parse($row->ticket_expires_at)) || ! hash_equals($row->ticket_hash, hash('sha256', $data['reset_ticket']))) {
                return false;
            }
            DB::table('password_reset_otps')->where('user_guid', $user->guid)->update(['ticket_hash' => null, 'ticket_expires_at' => null]);
            $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
            DB::table('refresh_tokens')->where('user_guid', $user->guid)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
            DB::table('device_tokens')->where('user_guid', $user->guid)->delete();
            DB::afterCommit(fn () => event(new PasswordReset($user)));

            return true;
        }, 3);
        if (! $success) {
            throw ValidationException::withMessages(['reset_ticket' => ['The reset ticket is invalid or expired.']]);
        }
    }

    private function assertConnection(): void
    {
        if ((new User)->getConnection() !== DB::connection()) {
            throw new \RuntimeException('Password reset requires a shared database connection.');
        }
    }

    private function digest(string $guid, string $email, string $issuance, string $otp): string
    {
        return hash_hmac('sha256', $guid.'|'.$email.'|password_reset|'.$issuance.'|'.$otp, config('app.key'));
    }
}
