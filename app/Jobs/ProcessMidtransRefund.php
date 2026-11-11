<?php

namespace App\Jobs;

use App\Services\BookingRefundService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One claimed step of a provider-API refund: dispatch with the persisted refund key, or verify an already dispatched refund.
 * Idempotent and lease-guarded, so duplicates, parallel workers and replays after a crash are harmless.
 */
class ProcessMidtransRefund implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 75;

    public array $backoff = [60, 300];

    public int $uniqueFor = 120;

    public function __construct(public string $refundGuid) {}

    public function uniqueId(): string
    {
        return $this->refundGuid;
    }

    public function handle(BookingRefundService $refunds): void
    {
        $refunds->processProviderRefund($this->refundGuid);
    }
}
