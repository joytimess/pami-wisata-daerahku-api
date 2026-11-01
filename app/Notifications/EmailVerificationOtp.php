<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailVerificationOtp extends Notification
{
    public function __construct(private string $otp) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Kode verifikasi email')
            ->line('Kode verifikasi email Anda: '.$this->otp)
            ->line('Kode berlaku selama 10 menit.')
            ->line('Jika Anda tidak meminta kode ini, abaikan email ini.');
    }
}
