<?php

namespace App\Services;

use App\Jobs\SendEmailVerificationOtp;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmailVerificationOtpService
{
    public function issue(User $user): void
    {
        $previous = DB::table('email_verification_otps')->where('user_guid', $user->guid)->lockForUpdate()->first();
        do {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while ($previous && hash_equals($previous->digest, $this->digest($user->guid, $user->email, $code)));
        $issuance = (string) Str::uuid();
        DB::table('email_verification_otps')->updateOrInsert(['user_guid' => $user->guid], [
            'email' => $user->email, 'purpose' => 'verify_email',
            'digest' => $this->digest($user->guid, $user->email, $code),
            'issuance_id' => $issuance, 'expires_at' => now()->addMinutes(10),
            'sent_at' => now(), 'attempts' => 0, 'consumed_at' => null,
        ]);
        SendEmailVerificationOtp::dispatch($user->guid, $issuance, $code)->afterCommit();
    }

    public function resend(string $email): void
    {
        DB::transaction(function () use ($email) {
            $user = User::where('email', $email)->lockForUpdate()->first();
            if (! $user || $user->role !== 0 || $user->status !== 'active' || $user->hasVerifiedEmail()) {
                return;
            }
            $otp = DB::table('email_verification_otps')->where('user_guid', $user->guid)->first();
            if (! $otp || now()->lt(Carbon::parse($otp->sent_at)->addSeconds(60))) {
                return;
            }
            $this->issue($user);
        }, 3);
    }

    public function verify(string $email, string $code): void
    {
        $verified = DB::transaction(function () use ($email, $code) {
            $user = User::where('email', $email)->lockForUpdate()->first();
            if (! $user || $user->role !== 0 || $user->status !== 'active' || $user->hasVerifiedEmail()) {
                return false;
            }
            $otp = DB::table('email_verification_otps')->where('user_guid', $user->guid)->lockForUpdate()->first();
            if (! $otp || $otp->purpose !== 'verify_email' || $otp->email !== $user->email || $otp->consumed_at || $otp->attempts >= 5 || now()->gte(Carbon::parse($otp->expires_at))) {
                return false;
            }
            if (! hash_equals($otp->digest, $this->digest($user->guid, $user->email, $code))) {
                DB::table('email_verification_otps')->where('user_guid', $user->guid)->increment('attempts');

                return false;
            }
            DB::table('email_verification_otps')->where('user_guid', $user->guid)->update(['consumed_at' => now()]);
            $user->markEmailAsVerified();
            DB::afterCommit(fn () => event(new Verified($user)));

            return true;
        }, 3);
        if (! $verified) {
            throw ValidationException::withMessages(['otp' => ['The verification code is invalid or expired.']]);
        }
    }

    private function digest(string $guid, string $email, string $code): string
    {
        return hash_hmac('sha256', $guid.'|'.$email.'|verify_email|'.$code, config('app.key'));
    }
}
