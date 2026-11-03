<?php

namespace App\Console\Commands;

use App\Services\TicketService;
use Illuminate\Console\Command;

class IssueEligibleTicketQr extends Command
{
    protected $signature = 'tickets:issue-qr';

    protected $description = 'Issue eligible private ticket QR secrets idempotently';

    public function handle(TicketService $tickets): int
    {
        $count = $tickets->issueDue();
        $this->info("Processed {$count} bookings.");

        return self::SUCCESS;
    }
}
