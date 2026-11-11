<?php

namespace App\Jobs;

use App\Services\MidtransPaymentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Processes one stored payment event: a notification receipt (authenticated lookup of the known order) or a verified event that
 * is waiting to be applied. Idempotent and claim-guarded, so duplicates and parallel workers are harmless.
 */
class ProcessMidtransPaymentEvent implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [30, 120, 300, 900];

    public int $uniqueFor = 120;

    public function __construct(public string $eventGuid) {}

    public function uniqueId(): string
    {
        return $this->eventGuid;
    }

    public function handle(MidtransPaymentService $payments): void
    {
        $payments->processEvent($this->eventGuid);
    }
}
