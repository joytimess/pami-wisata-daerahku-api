<?php

namespace App\Console\Commands;

use App\Services\StuckPaymentRecoveryService;
use Illuminate\Console\Command;

class RecoverStuckPayments extends Command
{
    protected $signature = 'payments:recover-stuck {--dry-run : Only report what would happen}';

    protected $description = 'Release Midtrans bookings parked in payment_verification/payment_review by an old timeout, using provider evidence only';

    public function handle(StuckPaymentRecoveryService $recovery): int
    {
        $dry = (bool) $this->option('dry-run');
        $r = $recovery->run($dry);
        $this->info(($dry ? '[dry-run] ' : '')."closable={$r['closable']} lookup={$r['lookup']} operator={$r['operator']}"
            .($dry ? '' : " closed={$r['closed']} lookups_requested={$r['lookups_requested']}"));
        foreach ($r['operator_guids'] as $guid) {
            $this->warn('needs operator: '.$guid);
        }

        return self::SUCCESS;
    }
}
