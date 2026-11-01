<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\EmailVerificationOtp;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;

class SendEmailVerificationOtp implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(public string $userGuid, public string $issuanceId, public string $otp) {}

    public function handle(): void
    {
        DB::transaction(function () {
            $user = User::whereKey($this->userGuid)->lockForUpdate()->first();
            $otp = DB::table('email_verification_otps')->where('user_guid', $this->userGuid)->lockForUpdate()->first();
            if (! $user || ! $otp || $user->role !== 0 || $user->status !== 'active' || $user->hasVerifiedEmail() || $otp->email !== $user->email || $otp->issuance_id !== $this->issuanceId || $otp->consumed_at || $otp->attempts >= 5 || now()->gte(Carbon::parse($otp->expires_at))) {
                return;
            }
            try {
                $name = config('mail.default');
                $config = config('mail.mailers.'.$name, []);
                $parsed = (new ConfigurationUrlParser)->parseConfiguration($config);
                $transport = $parsed['driver'] ?? $parsed['transport'] ?? null;
                if (config('mail.driver') || ! ($transport === 'smtp' || ($transport === 'array' && app()->environment('testing')))) {
                    throw new \RuntimeException;
                }
                $user->notifyNow(new EmailVerificationOtp($this->otp));
            } catch (\Throwable $exception) {
                throw new \RuntimeException('Verification email delivery failed.');
            }
        }, 3);
    }
}
