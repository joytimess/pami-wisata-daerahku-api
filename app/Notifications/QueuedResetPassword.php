<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class QueuedResetPassword extends ResetPassword implements ShouldBeEncrypted, ShouldQueue
{
    public function toMail($notifiable): MailMessage
    {
        $base = config('api_tokens.password_reset_url');
        $parts = is_string($base) ? parse_url($base) : false;
        if (! is_string($base) || ! filter_var($base, FILTER_VALIDATE_URL) || ! is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || str_contains(strtolower($parts['host']), 'your-https-client-domain')
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \RuntimeException('PASSWORD_RESET_URL must be configured as an HTTPS client URL without query or fragment.');
        }
        $url = $base.'?'.http_build_query(['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()], '', '&', PHP_QUERY_RFC3986);

        return $this->buildMailMessage($url);
    }
}
