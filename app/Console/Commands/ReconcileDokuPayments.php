<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileDokuPayment;
use App\Services\Doku\DokuClient;
use App\Services\DokuPaymentService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

#[Signature('payments:reconcile-doku {--limit=100 : Maximum events and attempts per run}')]
#[Description('Replay durable DOKU events and reconcile existing pending payments')]
class ReconcileDokuPayments extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DokuPaymentService $payments, DokuClient $client): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $failures = 0;
        $events = DB::table('payment_events')->where('provider', 'doku')->where('environment', $client->environment())
            ->where('verification', 'verified')->where('disposition', 'recorded')->orderBy('received_at')->orderBy('guid')->limit($limit)->pluck('guid');
        foreach ($events as $guid) {
            try {
                $payments->apply($guid);
            } catch (\Throwable) {
                $failures++;
                Log::warning('DOKU event replay failed.', ['event_guid' => $guid]);
            }
        }
        try {
            $escalated = $payments->escalateOverdue($limit);
        } catch (\Throwable) {
            $escalated = 0;
            $failures++;
            Log::warning('DOKU overdue-attempt escalation failed.');
        }
        if (config('doku.reconciliation_enabled') === true) {
            $attempts = DB::table('payment_attempts')->where('provider', 'doku')->where('environment', $client->environment())
                ->whereIn('status', ['initiating', 'pending', 'unknown'])->where('next_reconcile_at', '<=', now())
                ->orderBy('next_reconcile_at')->orderBy('guid')->limit($limit)->pluck('guid');
            foreach ($attempts as $guid) {
                try {
                    ReconcileDokuPayment::dispatch($guid);
                } catch (\Throwable) {
                    $failures++;
                    Log::warning('DOKU reconciliation awaits queue recovery.', ['attempt_guid' => $guid]);
                }
            }
        }
        $this->info('DOKU replay and reconciliation completed.'.($escalated > 0 ? ' Escalated overdue attempts: '.$escalated.'.' : ''));

        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
