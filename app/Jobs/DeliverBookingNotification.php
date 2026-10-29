<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Throwable;

class DeliverBookingNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $deliveryGuid) {}

    public function handle(): void
    {
        $now = now();
        $claimed = DB::table('notification_deliveries')->where('guid', $this->deliveryGuid)
            ->where(function ($query) use ($now) {
                $query->where(fn ($query) => $query->where('status', 'pending')->where('next_attempt_at', '<=', $now))
                    ->orWhere(fn ($query) => $query->where('status', 'processing')->where('updated_at', '<=', $now->copy()->subMinutes(15)));
            })->update(['status' => 'processing', 'updated_at' => $now]);
        if (! $claimed) {
            return;
        }
        $delivery = DB::table('notification_deliveries')->join('notifications', 'notifications.guid', '=', 'notification_deliveries.notification_guid')
            ->join('users', 'users.guid', '=', 'notifications.user_guid')
            ->where('notification_deliveries.guid', $this->deliveryGuid)
            ->select('notification_deliveries.*', 'notifications.user_guid', 'notifications.booking_guid', 'notifications.title', 'notifications.body', 'users.email', 'users.status as user_status')->first();
        if (! $delivery) {
            return;
        }
        try {
            if ($delivery->user_status !== 'active') {
                $status = 'cancelled';
            } elseif ($delivery->channel === 'fcm') {
                $device = DB::table('device_tokens')->where('guid', $delivery->target_key)->where('user_guid', $delivery->user_guid)->first();
                if (! $device) {
                    $status = 'cancelled';
                } else {
                    $credentials = config('api_tokens.firebase_credentials');
                    if (! app()->bound(Messaging::class) && ! $credentials) {
                        throw new \RuntimeException('fcm_unconfigured');
                    }
                    $messaging = app()->bound(Messaging::class)
                        ? app(Messaging::class)
                        : (new Factory)->withServiceAccount($credentials)->createMessaging();
                    $data = ['notification_guid' => $delivery->notification_guid];
                    if ($delivery->booking_guid !== null) {
                        $data['booking_guid'] = $delivery->booking_guid;
                    }
                    try {
                        $messaging->send(CloudMessage::fromArray(['token' => $device->token, 'data' => $data]));
                        $status = 'sent';
                    } catch (NotFound $exception) {
                        if ($exception->token() !== $device->token) {
                            throw $exception;
                        }
                        DB::table('device_tokens')->where('guid', $device->guid)->where('user_guid', $delivery->user_guid)->where('token', $device->token)->delete();
                        $status = 'cancelled';
                    }
                }
            } elseif ($delivery->channel === 'email') {
                $mailer = config('mail.default');
                if (in_array(config("mail.mailers.$mailer.transport"), ['log', 'array', 'null'], true)) {
                    throw new \RuntimeException('mail_unconfigured');
                }
                Mail::raw($delivery->body, fn ($message) => $message->to($delivery->email)->subject($delivery->title));
                $status = 'sent';
            } else {
                throw new \RuntimeException('channel_unconfigured');
            }
            DB::table('notification_deliveries')->where('guid', $delivery->guid)->update([
                'status' => $status, 'attempts' => $delivery->attempts + 1, 'next_attempt_at' => null,
                'last_error_code' => null, 'updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $attempts = $delivery->attempts + 1;
            DB::table('notification_deliveries')->where('guid', $delivery->guid)->update([
                'status' => 'pending', 'attempts' => $attempts,
                'next_attempt_at' => now()->addSeconds(min(3600, 60 * (2 ** min($attempts - 1, 6)))),
                'last_error_code' => in_array($exception->getMessage(), ['fcm_unconfigured', 'mail_unconfigured'], true) ? $exception->getMessage() : 'delivery_failed',
                'updated_at' => now(),
            ]);
            Log::warning('Notification delivery failed.', ['delivery_guid' => $delivery->guid]);
        }
    }
}
