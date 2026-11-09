<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\AdminDestinationScopeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Throwable;

class DeliverBookingNotification implements ShouldQueue
{
    use Queueable;

    /** Event types whose email must only ever reach a verified address. */
    private const VERIFIED_RECIPIENT_TYPES = ['booking.payment_receipt', 'booking.ticket_issued'];

    /** Upper bound on delivery tries (including abandoned claims); afterwards the delivery is marked failed for operators. */
    private const MAX_ATTEMPTS = 10;

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
            ->select('notification_deliveries.*', 'notifications.user_guid', 'notifications.booking_guid', 'notifications.title', 'notifications.body', 'notifications.type', 'users.email', 'users.email_verified_at', 'users.status as user_status')->first();
        if (! $delivery) {
            return;
        }
        [$attemptGuid, $attemptNumber] = $this->openAttempt($delivery->guid, $now);
        if ($attemptNumber > self::MAX_ATTEMPTS) {
            DB::table('notification_deliveries')->where('guid', $delivery->guid)->update([
                'status' => 'failed', 'next_attempt_at' => null, 'last_error_code' => 'max_attempts_exceeded', 'updated_at' => now(),
            ]);
            $this->closeAttempt($attemptGuid, 'failed', 'max_attempts_exceeded');

            return;
        }
        try {
            $authorized = true;
            if ($delivery->booking_guid && in_array($delivery->type, ['booking.created', 'booking.cancelled', 'booking.payment_review'], true)) {
                $user = User::find($delivery->user_guid);
                $destination = DB::table('bookings')->join('destination_quotas', 'destination_quotas.guid', '=', 'bookings.destination_quota_guid')->where('bookings.guid', $delivery->booking_guid)->value('destination_quotas.destination_guid');
                $authorized = $user && $destination && $user->status === 'active' && in_array((int) $user->role, [1, 2], true)
                    && app(AdminDestinationScopeService::class)->allows($user, $destination);
            }
            $unverifiedRecipient = $delivery->channel === 'email' && in_array($delivery->type, self::VERIFIED_RECIPIENT_TYPES, true) && $delivery->email_verified_at === null;
            if ($delivery->user_status !== 'active' || ! $authorized || $unverifiedRecipient) {
                $status = 'cancelled';
                $errorCode = $unverifiedRecipient ? 'email_unverified' : null;
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
                DB::table('notification_delivery_attempts')->where('guid', $attemptGuid)->update(['submitted_at' => now(), 'updated_at' => now()]);
                Mail::raw($delivery->body, fn ($message) => $message->to($delivery->email)->subject($delivery->title));
                $status = 'sent';
            } else {
                throw new \RuntimeException('channel_unconfigured');
            }
            DB::table('notification_deliveries')->where('guid', $delivery->guid)->update([
                'status' => $status, 'attempts' => $delivery->attempts + 1, 'next_attempt_at' => null,
                'last_error_code' => $errorCode ?? null, 'updated_at' => now(),
            ]);
            try {
                $this->closeAttempt($attemptGuid, $status, $errorCode ?? null);
            } catch (Throwable) {
                // Audit bookkeeping must never turn a delivered message back into a pending one.
                Log::warning('Notification attempt audit could not be closed.', ['delivery_guid' => $delivery->guid]);
            }
        } catch (Throwable $exception) {
            $attempts = $delivery->attempts + 1;
            DB::table('notification_deliveries')->where('guid', $delivery->guid)->update([
                'status' => 'pending', 'attempts' => $attempts,
                'next_attempt_at' => now()->addSeconds(min(3600, 60 * (2 ** min($attempts - 1, 6)))),
                'last_error_code' => in_array($exception->getMessage(), ['fcm_unconfigured', 'mail_unconfigured'], true) ? $exception->getMessage() : 'delivery_failed',
                'updated_at' => now(),
            ]);
            $this->closeAttempt($attemptGuid, 'failed', in_array($exception->getMessage(), ['fcm_unconfigured', 'mail_unconfigured'], true) ? $exception->getMessage() : 'delivery_failed');
            Log::warning('Notification delivery failed.', ['delivery_guid' => $delivery->guid]);
        }
    }

    /**
     * Opens an append-only audit row. A previous row still in `submitting` means a worker died after claiming, so the
     * transport may already have accepted that message: it is marked `ambiguous` as evidence, and the delivery is still
     * retried, so a duplicate email is possible in that window. Retries are capped by MAX_ATTEMPTS.
     *
     * @return array{0: string, 1: int} attempt guid and number
     */
    private function openAttempt(string $deliveryGuid, Carbon $now): array
    {
        DB::table('notification_delivery_attempts')->where('delivery_guid', $deliveryGuid)->where('outcome', 'submitting')
            ->update(['outcome' => 'ambiguous', 'error_code' => 'worker_lost', 'finished_at' => $now, 'updated_at' => $now]);
        $guid = (string) Str::uuid();
        $number = ((int) DB::table('notification_delivery_attempts')->where('delivery_guid', $deliveryGuid)->max('attempt_number')) + 1;
        DB::table('notification_delivery_attempts')->insert([
            'guid' => $guid, 'delivery_guid' => $deliveryGuid,
            'attempt_number' => $number,
            'outcome' => 'submitting', 'claimed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return [$guid, $number];
    }

    private function closeAttempt(string $attemptGuid, string $outcome, ?string $errorCode): void
    {
        DB::table('notification_delivery_attempts')->where('guid', $attemptGuid)->update([
            'outcome' => $outcome, 'error_code' => $errorCode, 'finished_at' => now(), 'updated_at' => now(),
        ]);
    }
}
