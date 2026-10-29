<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class QueuedResetPassword extends ResetPassword implements ShouldQueue
{
    public function toMail($notifiable): MailMessage
    {
        $base = config('api_tokens.password_reset_url');
        if (! is_string($base) || ! filter_var($base, FILTER_VALIDATE_URL)) {
            throw new \RuntimeException('PASSWORD_RESET_URL must be configured as an application URL.');
        }
        $url = $base.(str_contains($base, '?') ? '&' : '?').http_build_query(['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]);

        return $this->buildMailMessage($url);
    }
}
