<?php

use App\Models\User;
use App\Services\BookingService;
use App\Services\Doku\DokuClient;
use App\Services\DokuPaymentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! env('MYSQL_CONCURRENCY_TESTS') || config('database.default') !== 'mysql'
    || ! preg_match('/^pami_doku_[a-z0-9]{12}_test$/D', (string) config('database.connections.mysql.database'))) {
    exit(2);
}
[, $ready, $go, $json, $resultFile, $calls] = $argv;
config(['operations.demo_payments_enabled' => false, 'doku.environment' => 'sandbox']);
Http::preventStrayRequests();
$app->instance(DokuClient::class, new class($calls) extends DokuClient
{
    public function __construct(private string $calls) {}

    public function readinessReason(): ?string
    {
        return null;
    }

    public function configured(): bool
    {
        return true;
    }

    public function create(object $attempt, object $booking): array
    {
        assert(DB::transactionLevel() === 0);
        file_put_contents($this->calls, "create\n", FILE_APPEND | LOCK_EX);
        usleep(150000);

        return ['status' => 'pending', 'provider_reference' => 'test-'.$attempt->guid, 'expires_at' => $booking->payment_expires_at,
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
        default => throw new RuntimeException('Unsupported DOKU concurrency action.'),
    };
    $result = ['ok' => true, 'data' => $data];
} catch (Throwable $exception) {
    $result = ['ok' => false, 'code' => $exception->errorCode ?? null, 'class' => get_class($exception)];
}
file_put_contents($resultFile, json_encode($result, JSON_THROW_ON_ERROR));
