<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetOtp extends Notification
{
    public function __construct(private string $otp) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Kode reset password')
            ->line('Kode reset password Anda: '.$this->otp)
            ->line('Kode berlaku selama 10 menit.')
            ->line('Jangan bagikan kode ini. Jika Anda tidak memintanya, abaikan email ini.');
    }
}
