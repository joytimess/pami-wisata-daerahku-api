<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\PasswordResetOtp;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;

class SendPasswordResetOtp implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(public string $userGuid, public string $issuanceId, public string $otp) {}

    public function handle(): void
    {
        $user = User::find($this->userGuid);
        $row = DB::table('password_reset_otps')->where('user_guid', $this->userGuid)->first();
        if (! $user || ! $row || $row->email !== strtolower(trim($user->email)) || $row->issuance_id !== $this->issuanceId || $row->consumed_at || $row->attempts >= 5 || now()->gte(Carbon::parse($row->expires_at))) {
            return;
        }
        try {
            $name = config('mail.default');
            $parsed = (new ConfigurationUrlParser)->parseConfiguration(config('mail.mailers.'.$name, []));
            $transport = $parsed['driver'] ?? $parsed['transport'] ?? null;
            if (config('mail.driver') || ! ($transport === 'smtp' || ($transport === 'array' && app()->environment('testing')))) {
                throw new \RuntimeException;
            }
            $user->notifyNow(new PasswordResetOtp($this->otp));
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Password reset OTP delivery failed.');
        }
    }
}
