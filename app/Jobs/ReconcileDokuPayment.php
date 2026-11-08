<?php

namespace App\Jobs;

use App\Services\DokuPaymentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileDokuPayment implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 75;

    public array $backoff = [60, 300];

    public int $uniqueFor = 3600;

    public function __construct(public string $attemptGuid) {}

    public function uniqueId(): string
    {
        return $this->attemptGuid;
    }

    /**
     * Execute the job.
     */
    public function handle(DokuPaymentService $payments): void
    {
        $payments->reconcile($this->attemptGuid);
    }
}
