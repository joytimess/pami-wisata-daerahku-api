<?php

namespace App\Console\Commands;

use App\Jobs\ProcessMidtransPaymentEvent;
use App\Jobs\ProcessMidtransRefund;
use App\Jobs\ReconcileMidtransPayment;
use App\Services\Midtrans\MidtransChannelRegistry;
use App\Services\MidtransPaymentService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('payments:reconcile-midtrans {--limit=100 : Maximum events and attempts per scan}')]
#[Description('Replay durable Midtrans events and receipts and reconcile open payments through the authenticated Status API')]
class ReconcileMidtransPayments extends Command
{
    /**
     * Four bounded scans, each independent of the new-charge kill switch (existing orders must stay recoverable):
     * verified events not yet applied, notification receipts not yet processed, open attempts that are due, overdue escalation.
     * Provider-facing work is queued, never done inline, so a slow provider cannot stall the scheduler.
     */
    public function handle(MidtransPaymentService $payments, MidtransChannelRegistry $registry): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $environment = $registry->environment();
        $failures = 0;

        $events = DB::table('payment_events')->where('provider', 'midtrans')->where('environment', $environment)
            ->where('verification', 'verified')->where('disposition', 'recorded')->orderBy('received_at')->orderBy('guid')->limit($limit)->pluck('guid');
        foreach ($events as $guid) {
            try {
                $payments->applyEvidence($guid);
            } catch (Throwable) {
                $failures++;
                Log::warning('Midtrans event replay failed.', ['event_guid' => $guid]);
            }
        }

        if ($registry->verificationReason() === null) {
            $receipts = DB::table('payment_events')->where('provider', 'midtrans')->where('environment', $environment)
                ->where('verification', 'unverified')->where('disposition', 'received')->whereNotNull('payment_attempt_guid')
                ->where(fn ($query) => $query->whereNull('claimed_until')->orWhere('claimed_until', '<=', now()))
                ->orderBy('received_at')->orderBy('guid')->limit($limit)->pluck('guid');
            foreach ($receipts as $guid) {
                try {
                    ProcessMidtransPaymentEvent::dispatch($guid);
                } catch (Throwable) {
                    $failures++;
                    Log::warning('Midtrans receipt processing awaits queue recovery.', ['event_guid' => $guid]);
                }
            }
            $attempts = DB::table('payment_attempts')->where('provider', 'midtrans')->where('environment', $environment)
                ->whereIn('status', ['initiating', 'pending', 'unknown'])->where('next_reconcile_at', '<=', now())
                ->orderBy('next_reconcile_at')->orderBy('guid')->limit($limit)->pluck('guid');
            foreach ($attempts as $guid) {
                try {
                    ReconcileMidtransPayment::dispatch($guid);
                } catch (Throwable) {
                    $failures++;
                    Log::warning('Midtrans reconciliation awaits queue recovery.', ['attempt_guid' => $guid]);
                }
            }
        }

        if ($registry->verificationReason() === null) {
            // Claimed provider refunds that are due: first dispatch, a re-send of the SAME key, or verification of an accepted refund.
            $refunds = DB::table('booking_refund_requests as r')->join('payment_attempts as a', 'a.guid', '=', 'r.payment_attempt_guid')
                ->where('a.provider', 'midtrans')->where('a.environment', $environment)
                ->where('r.refund_route', 'provider_api')->where('r.status', 'refund_pending')->where('r.next_reconcile_at', '<=', now())
                ->where(fn ($lease) => $lease->whereNull('r.dispatch_claimed_until')->orWhere('r.dispatch_claimed_until', '<=', now()))
                ->orderBy('r.next_reconcile_at')->orderBy('r.guid')->limit($limit)->pluck('r.guid');
            foreach ($refunds as $guid) {
                try {
                    ProcessMidtransRefund::dispatch($guid);
                } catch (Throwable) {
                    $failures++;
                    Log::warning('Midtrans refund awaits queue recovery.', ['refund_request_guid' => $guid]);
                }
            }
        }

        try {
            $escalated = $payments->escalateOverdue($limit);
        } catch (Throwable) {
            $escalated = 0;
            $failures++;
            Log::warning('Midtrans overdue-attempt escalation failed.');
        }
        $this->info('Midtrans replay and reconciliation completed.'.($escalated > 0 ? ' Escalated overdue attempts: '.$escalated.'.' : ''));

        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
