<?php

use App\Models\User;
use App\Services\BookingRefundService;
use App\Services\BookingService;
use App\Services\PaymentSettlementService;
use App\Services\TicketCheckInService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Worker of StaleSnapshotConcurrencyTest: one real service call that decides from a snapshot older than the competing commit.
 *
 * How the stale snapshot is forced. Every job runs on one MySQL connection (REPEATABLE READ).
 *
 * - snapshot "explicit": the worker opens an OUTER transaction (DB::beginTransaction) and immediately does a plain consistent read of
 *   the booking, its tickets, refund requests and payment rows (snsProbe). InnoDB creates the read view on that first consistent read,
 *   so the view is frozen before the worker announces itself. The service's own DB::transaction then becomes a savepoint inside this
 *   transaction and therefore keeps reading through the frozen view; only its locking reads (FOR UPDATE / FOR SHARE) see newer commits.
 *   After the call the worker runs the probe again inside the same transaction: any value the competing side changed that is still
 *   unchanged proves the view stayed stale for the whole call. The outer transaction is committed last.
 * - snapshot "natural": no outer transaction. The service is entered cold; its own first plain read (booking lookup / event join)
 *   creates the snapshot before it requests the first lock, which is exactly the production shape of the race.
 *
 * Barrier protocol, all through files in the run directory: the worker writes `snapshot` (its MySQL connection id and the probe taken
 * with its view) and then waits for `go`. The parent holds the first lock the service needs, releases `go`, proves from the process
 * list that this connection is blocked on that lock, lets the competing side commit and only then releases the lock. The worker finally
 * writes `result`. No sleeps decide the order.
 */
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

require_once __DIR__.'/DisposableMysqlGuard.php';
require_once __DIR__.'/../Feature/Support/MidtransFixtures.php';
require_once __DIR__.'/stale-snapshot-support.php';
try {
    assertDisposableMysqlAccount(config('database.connections.mysql'), (string) getenv('DB_DATABASE'));
} catch (RuntimeException) {
    exit(2);
}
[, $directory, $json] = $argv;
$job = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

mtxConfig($job['config'] ?? []);
if (isset($job['now'])) {
    CarbonImmutable::setTestNow(CarbonImmutable::parse($job['now']));
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse($job['now']));
}
Queue::fake();
Http::preventStrayRequests();

$probeBefore = null;
if ($job['snapshot'] === 'explicit') {
    DB::beginTransaction();
    $probeBefore = snsProbe($job['booking_guid']);
}
file_put_contents($directory.'/snapshot', json_encode(['connection_id' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id,
    'probe_before' => $probeBefore], JSON_THROW_ON_ERROR));

$deadline = microtime(true) + 40;
while (! file_exists($directory.'/go') && microtime(true) < $deadline) {
    usleep(1000);
}
if (! file_exists($directory.'/go')) {
    exit(3);
}

$result = ['ok' => false];
try {
    $data = match ($job['action']) {
        'refund_decide' => app(BookingRefundService::class)->decide(User::findOrFail($job['admin_guid']), $job['booking_guid'], $job['refund_guid'],
            ['decision' => 'approve', 'note' => 'Disetujui pengelola'])['status'],
        'check_in' => app(TicketCheckInService::class)->confirm(User::findOrFail($job['admin_guid']), $job['payload'], $job['key'])['status'],
        'settle_manually' => app(BookingRefundService::class)->settleManually(User::findOrFail($job['admin_guid']), $job['booking_guid'], $job['refund_guid'],
            $job['settlement'])['status'],
        'apply_event' => (function () use ($job): string {
            app(PaymentSettlementService::class)->apply($job['event_guid']);

            return 'applied';
        })(),
        'expire_due' => (function (): string {
            app(BookingService::class)->expireDue();

            return 'done';
        })(),
        default => throw new RuntimeException('Unsupported stale snapshot action.'),
    };
    $result = ['ok' => true, 'data' => $data];
} catch (Throwable $exception) {
    $result = ['ok' => false, 'code' => $exception->errorCode ?? null, 'class' => get_class($exception), 'message' => substr($exception->getMessage(), 0, 200)];
}
if ($job['snapshot'] === 'explicit') {
    $result['probe_before'] = $probeBefore;
    $result['probe_after'] = snsProbe($job['booking_guid']);
    DB::commit();
}
file_put_contents($directory.'/result', json_encode($result, JSON_THROW_ON_ERROR));
