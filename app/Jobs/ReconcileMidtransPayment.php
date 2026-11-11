<?php

namespace App\Jobs;

use App\Services\MidtransPaymentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** One claimed Status lookup of a due Midtrans attempt (missing webhook, lost Charge response, unknown outcome). */
class ReconcileMidtransPayment implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 75;

    public array $backoff = [60, 300];

    public int $uniqueFor = 120;

    public function __construct(public string $attemptGuid) {}

    public function uniqueId(): string
    {
        return $this->attemptGuid;
    }

    public function handle(MidtransPaymentService $payments): void
    {
        $payments->reconcile($this->attemptGuid);
    }
}
