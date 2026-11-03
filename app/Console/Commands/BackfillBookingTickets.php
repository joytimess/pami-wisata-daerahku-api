<?php

namespace App\Console\Commands;

use App\Services\TicketService;
use Illuminate\Console\Command;

class BackfillBookingTickets extends Command
{
    protected $signature = 'tickets:backfill';

    protected $description = 'Backfill booking units and preserve legacy check-in audits';

    public function handle(TicketService $tickets): int
    {
        $count = $tickets->backfill();
        $this->info("Processed {$count} bookings.");

        return self::SUCCESS;
    }
}
