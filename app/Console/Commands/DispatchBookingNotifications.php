<?php

namespace App\Console\Commands;

use App\Jobs\DeliverBookingNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchBookingNotifications extends Command
{
    protected $signature = 'notifications:dispatch';

    protected $description = 'Queue due durable notification deliveries';

    public function handle(): int
    {
        DB::table('notification_deliveries')->where(function ($query) {
            $query->where(fn ($query) => $query->where('status', 'pending')->where('next_attempt_at', '<=', now()))
                ->orWhere(fn ($query) => $query->where('status', 'processing')->where('updated_at', '<=', now()->subMinutes(15)));
        })->orderBy('guid')->chunkById(100, function ($deliveries) {
            foreach ($deliveries as $delivery) {
                DeliverBookingNotification::dispatch($delivery->guid);
            }
        }, 'guid');

        return self::SUCCESS;
    }
}
