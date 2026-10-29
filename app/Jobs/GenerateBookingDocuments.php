<?php

namespace App\Jobs;

use App\Services\BookingDocumentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateBookingDocuments implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [60, 300, 900, 1800];

    public function __construct(public string $bookingGuid) {}

    public function handle(BookingDocumentService $documents): void
    {
        $documents->generate($this->bookingGuid);
    }
}
