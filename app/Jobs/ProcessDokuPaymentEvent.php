<?php

namespace App\Jobs;

use App\Services\DokuPaymentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessDokuPaymentEvent implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [30, 120, 300, 900];

    public int $uniqueFor = 3600;

    public function __construct(public string $eventGuid) {}

    public function uniqueId(): string
    {
        return $this->eventGuid;
    }

    /**
     * Execute the job.
     */
    public function handle(DokuPaymentService $payments): void
    {
        $payments->apply($this->eventGuid);
    }
}
