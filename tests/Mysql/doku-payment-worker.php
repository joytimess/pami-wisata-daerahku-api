<?php

use App\Models\User;
use App\Services\BookingRefundService;
use App\Services\BookingService;
use App\Services\Doku\DokuClient;
use App\Services\DokuPaymentService;
use App\Services\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! env('MYSQL_CONCURRENCY_TESTS') || config('database.default') !== 'mysql'
    || config('database.connections.mysql.url') || config('database.connections.mysql.read') || config('database.connections.mysql.write')
    || app()->configurationIsCached()
    || ! preg_match('/^pami_doku_[a-z0-9]{12}_test$/D', (string) config('database.connections.mysql.database'))) {
    exit(2);
}
if (DB::selectOne('SELECT DATABASE() AS db')->db !== config('database.connections.mysql.database')) {
    exit(2);
}
[, $ready, $go, $json, $resultFile, $calls] = $argv;
config(['operations.demo_payments_enabled' => false, 'doku.environment' => 'sandbox', 'doku.verified_channels' => ['va_bni']]);
Http::preventStrayRequests();
$app->instance(DokuClient::class, new class($calls) extends DokuClient
{
    public function __construct(private string $calls) {}

    public function readinessReason(string $channelCode = 'va_bni'): ?string
    {
        return null;
    }

    public function expiryWindowReason(string $channelCode, CarbonImmutable $expiresAt): ?string
    {
        return null;
    }

    public function configured(): bool
    {
        return true;
    }

    public function create(object $attempt, object $booking): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Provider create must run outside a database transaction.');
        }
        file_put_contents($this->calls, "create\n", FILE_APPEND | LOCK_EX);
        usleep(150000);

        return ['status' => 'pending', 'provider_reference' => 'test-'.$attempt->guid, 'expires_at' => $attempt->instruction_expires_at,
            'instruction' => ['presentation_type' => 'virtual_account', 'bank_code' => 'bni', 'va_number' => 'test-'.$attempt->guid, 'copyable' => true]];
    }
});
$job = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
touch($ready);
$deadline = microtime(true) + 25;
while (! file_exists($go) && microtime(true) < $deadline) {
    usleep(1000);
}
if (! file_exists($go)) {
    exit(3);
}
try {
    $data = match ($job['action']) {
        'initiate' => app(DokuPaymentService::class)->initiate(User::findOrFail($job['user_guid']), $job['booking_guid'], $job['input']),
        'receive' => app(DokuPaymentService::class)->receive($job['outcome']),
        'expire' => app(BookingService::class)->expireDue(),
        'issue_tickets' => app(TicketService::class)->issue($job['booking_guid'])['ticket_state'],
        'refund_request' => app(BookingRefundService::class)->request(User::findOrFail($job['user_guid']), $job['booking_guid'], $job['input']),
        'refund_decide' => app(BookingRefundService::class)->decide(User::findOrFail($job['user_guid']), $job['booking_guid'], $job['refund_guid'], $job['input']),
        'refund_settle' => app(BookingRefundService::class)->settleManually(User::findOrFail($job['user_guid']), $job['booking_guid'], $job['refund_guid'], $job['input']),
        default => throw new RuntimeException('Unsupported DOKU concurrency action.'),
    };
    $result = ['ok' => true, 'data' => $data];
} catch (Throwable $exception) {
    $result = ['ok' => false, 'code' => $exception->errorCode ?? null, 'class' => get_class($exception)];
}
file_put_contents($resultFile, json_encode($result, JSON_THROW_ON_ERROR));
