<?php

namespace App\Jobs;

use App\Services\TicketService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Issues entry QR units for a booking right after its payment is verified. TicketService::issue() is idempotent and
 * decides eligibility itself (payment, approval, local H-5 window), so a duplicate or early job is a harmless no-op;
 * the scheduler command remains the recovery path.
 */
class IssueBookingTicketQr implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [30, 120];

    public int $uniqueFor = 300;

    public function __construct(public string $bookingGuid) {}

    public function uniqueId(): string
    {
        return $this->bookingGuid;
    }

    public function handle(TicketService $tickets): void
    {
        $tickets->issue($this->bookingGuid);
    }
}
