<?php

namespace App\Console\Commands;

use App\Services\BookingService;
use Illuminate\Console\Command;

class ExpireBookings extends Command
{
    protected $signature = 'bookings:expire';

    protected $description = 'Advance due booking deadlines safely';

    public function handle(BookingService $bookings): int
    {
        $bookings->expireDue();

        return self::SUCCESS;
    }
}
