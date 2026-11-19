<?php

namespace App\Console\Commands;

use App\Services\BookingTimezoneService;
use Illuminate\Console\Command;

class BackfillBookingTimezoneSnapshots extends Command
{
    protected $signature = 'bookings:backfill-timezone-snapshot {--dry-run : Only report how many bookings would be filled}';

    protected $description = 'Fill destination_timezone_snapshot for old bookings that never stored one';

    public function handle(BookingTimezoneService $timezones): int
    {
        $dry = (bool) $this->option('dry-run');
        $count = $timezones->backfillSnapshots($dry);
        $this->info(($dry ? 'Would fill ' : 'Filled ').$count.' booking timezone snapshot(s).');

        return self::SUCCESS;
    }
}
