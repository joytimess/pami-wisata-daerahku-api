<?php

use App\Models\User;
use App\Services\BookingRefundService;
use App\Services\BookingService;
use App\Services\MidtransPaymentService;
use App\Services\TicketCheckInService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

require_once __DIR__.'/DisposableMysqlGuard.php';
require_once __DIR__.'/../Feature/Support/MidtransFixtures.php';
try {
    assertDisposableMysqlAccount(config('database.connections.mysql'), (string) getenv('DB_DATABASE'));
} catch (RuntimeException) {
    exit(2);
}
[, $ready, $go, $json, $resultFile, $calls] = $argv;
$job = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

// Same sandbox configuration the parent seeded with. No real credential, host or provider is ever involved.
mtxConfig($job['config'] ?? []);
if (isset($job['now'])) {
    // A job may run on the local visit day of the seeded booking (check-in is only open on that date).
    CarbonImmutable::setTestNow(CarbonImmutable::parse($job['now']));
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse($job['now']));
}
// Notification receipts and ticket jobs are only recorded, never executed: the worker exercises the service, not a queue.
Queue::fake();
Http::preventStrayRequests();
// The only reachable endpoint is a faked Status lookup. The body is generated from the seeded attempt row so that it is a
// consistent, verifiable answer for exactly that order. Every lookup is counted in the shared calls file.
Http::fake(['https://api.sandbox.midtrans.com/v2/*/status' => function (Request $request) use ($calls, $job) {
    file_put_contents($calls, (DB::transactionLevel() === 0 ? "status\n" : "status-in-transaction\n"), FILE_APPEND | LOCK_EX);
    usleep(150000);
    preg_match('#/v2/([^/]+)/status$#', $request->url(), $match);
    $attempt = DB::table('payment_attempts')->where('merchant_reference', rawurldecode((string) ($match[1] ?? '')))->first();
    if (! $attempt) {
        return Http::response(['status_code' => '404', 'status_message' => 'Transaction doesn\'t exist.'], 200);
    }

    // A payment the provider saw some minutes ago (before a deadline that has passed since).
    $override = isset($job['settled_minutes_ago']) ? ['settlement_time' => mtxWib(now()->subMinutes((int) $job['settled_minutes_ago']))] : [];

    return Http::response(mtxStatusBody($attempt, $job['provider_state'] ?? 'settlement', $override), 200);
},
    // Phase 6: the provider's cancel answer (counted); the Status stub above decides what the lookup then proves.
    'https://api.sandbox.midtrans.com/v2/*/cancel' => function (Request $request) use ($calls) {
        file_put_contents($calls, (DB::transactionLevel() === 0 ? "cancel
" : "cancel-in-transaction
"), FILE_APPEND | LOCK_EX);
        usleep(100000);
        preg_match('#/v2/([^/]+)/cancel$#', $request->url(), $match);

        return Http::response(['status_code' => '200', 'order_id' => rawurldecode((string) ($match[1] ?? '')), 'transaction_status' => 'cancel'], 200);
    },
    // Phase 4: a faked Charge (counted) and a faked full Refund echoing the request (counted). Neither reaches a real host.
    'https://api.sandbox.midtrans.com/v2/charge' => function (Request $request) use ($calls) {
        file_put_contents($calls, (DB::transactionLevel() === 0 ? "charge\n" : "charge-in-transaction\n"), FILE_APPEND | LOCK_EX);
        usleep(100000);

        return (mtxCharge())($request);
    },
    'https://api.sandbox.midtrans.com/v2/*/refund' => function (Request $request) use ($calls) {
        file_put_contents($calls, (DB::transactionLevel() === 0 ? "refund\n" : "refund-in-transaction\n"), FILE_APPEND | LOCK_EX);
        usleep(150000);
        preg_match('#/v2/([^/]+)/refund$#', $request->url(), $match);
        $attempt = DB::table('payment_attempts')->where('merchant_reference', rawurldecode((string) ($match[1] ?? '')))->first();
        if (! $attempt) {
            return Http::response(['status_code' => '404', 'status_message' => 'Transaction doesn\'t exist.'], 200);
        }

        return Http::response((mtxRefundAccepted($attempt))($request), 200);
    }]);

touch($ready);
$deadline = microtime(true) + 25;
while (! file_exists($go) && microtime(true) < $deadline) {
    usleep(1000);
}
if (! file_exists($go)) {
    exit(3);
}
try {
    $service = app(MidtransPaymentService::class);
    $data = match ($job['action']) {
        // Scheduler entry: a claimed Status lookup of a due attempt.
        'reconcile' => $service->reconcile($job['attempt_guid']),
        // Owner refresh ('refresh' mode lookup) of the owner's own attempt.
        'refresh' => $service->refresh(User::findOrFail($job['user_guid']), $job['booking_guid'], $job['attempt_guid']),
        // Stored notification receipt processed by a queue worker ('notification' mode lookup).
        'process_event' => $service->processEvent($job['event_guid']),
        // The webhook controller's service call, after signature validation.
        'receive_notification' => $service->receiveNotification($job['payload'], $job['raw_body']),
        // Applies whatever verified-but-unapplied evidence exists for the attempt, repeatedly, to overlap with the lookup winner.
        'apply_recorded' => (function () use ($service, $job): int {
            $until = microtime(true) + 3;
            $applied = 0;
            do {
                foreach (DB::table('payment_events')->where('payment_attempt_guid', $job['attempt_guid'])->where('verification', 'verified')
                    ->where('disposition', 'recorded')->pluck('guid') as $eventGuid) {
                    $service->applyEvidence($eventGuid);
                    $applied++;
                }
                usleep(5000);
            } while (microtime(true) < $until && DB::table('payment_attempts')->where('guid', $job['attempt_guid'])->value('status') !== 'paid');

            return $applied;
        })(),
        // The result of a Charge arriving (possibly late) for a reserved attempt: the private persistence step of initiate().
        'save_initiation' => (function () use ($service, $job): string {
            $attempt = DB::table('payment_attempts')->where('guid', $job['attempt_guid'])->first();
            $booking = DB::table('bookings')->where('guid', $job['booking_guid'])->first();
            $result = ['status' => 'pending', 'provider_reference' => 'trx-'.$attempt->guid, 'presentation_type' => 'virtual_account',
                'expires_at' => CarbonImmutable::parse($attempt->instruction_expires_at, 'UTC')->toIso8601String(),
                'instruction' => ['presentation_type' => 'virtual_account', 'bank_code' => $attempt->channel, 'va_number' => '81234500001', 'copyable' => true]];
            usleep((int) ($job['delay_ms'] ?? 0) * 1000);
            $method = new ReflectionMethod(MidtransPaymentService::class, 'saveInitiation');

            return $method->invoke($service, $attempt, $booking, $result)['status'];
        })(),
        // Phase 4: the scheduler's expiry pass over due bookings.
        'expire_due' => (function (): string {
            app(BookingService::class)->expireDue();

            return 'done';
        })(),
        // Phase 4: the owner's Charge request through the service (different idempotency keys may race).
        'initiate' => (function () use ($service, $job): string {
            $response = $service->initiate(User::findOrFail($job['user_guid']), $job['booking_guid'],
                ['idempotency_key' => $job['key'], 'method_code' => $job['method'] ?? 'virtual_account', 'channel' => $job['channel'] ?? 'bca']);

            return (string) ($response['status'] ?? $response['data']['status'] ?? 'ok');
        })(),
        // Phase 6: the owner cancels the booking (the provider order is cancelled and proven closed first).
        'cancel_booking' => app(BookingService::class)->cancel(User::findOrFail($job['user_guid']), $job['booking_guid'])->status,
        // Phase 4: an admin approves the owner's refund request.
        'refund_decide' => app(BookingRefundService::class)->decide(User::findOrFail($job['admin_guid']), $job['booking_guid'], $job['refund_guid'],
            ['decision' => 'approve', 'note' => 'Disetujui pengelola'])['status'],
        // Phase 4: the queue worker that sends/continues the provider refund.
        'process_refund' => app(BookingRefundService::class)->processProviderRefund($job['refund_guid']),
        // Phase 4: the scanner confirms a ticket.
        'check_in' => app(TicketCheckInService::class)->confirm(User::findOrFail($job['admin_guid']), $job['payload'], $job['key'])['status'],
        default => throw new RuntimeException('Unsupported Midtrans concurrency action.'),
    };
    $result = ['ok' => true, 'data' => $data];
} catch (Throwable $exception) {
    $result = ['ok' => false, 'code' => $exception->errorCode ?? null, 'class' => get_class($exception), 'message' => substr($exception->getMessage(), 0, 200)];
}
file_put_contents($resultFile, json_encode($result, JSON_THROW_ON_ERROR));
