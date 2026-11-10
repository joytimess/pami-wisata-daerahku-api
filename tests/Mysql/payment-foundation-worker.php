<?php

use App\Models\User;
use App\Services\BookingService;
use App\Services\PaymentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

require_once __DIR__.'/DisposableMysqlGuard.php';
try {
    assertDisposableMysqlAccount(config('database.connections.mysql'), (string) getenv('DB_DATABASE'));
} catch (RuntimeException) {
    exit(2);
}

[, $ready, $go, $json, $resultFile] = $argv;
$job = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
config(['operations.doku_enabled' => false]);
Http::preventStrayRequests();
touch($ready);
$deadline = microtime(true) + 25;
while (! file_exists($go) && microtime(true) < $deadline) {
    usleep(1000);
}
if (! file_exists($go)) {
    exit(3);
}

try {
    if ($job['action'] === 'insert' && in_array($job['table'], ['payment_intents', 'payment_attempts', 'payment_events'], true)) {
        DB::table($job['table'])->insert($job['row']);
    } elseif ($job['action'] === 'expire') {
        app(BookingService::class)->expireDue();
    } elseif ($job['action'] === 'read') {
        $owner = User::findOrFail($job['user_guid']);
        $service = app(PaymentService::class);
        $status = $service->show($owner, $job['booking_guid']);
        $methods = $service->methods($owner, $job['booking_guid']);
        if (($status['payment_status'] ?? null) !== 'unverified' || ($status['can_pay'] ?? true) !== false
            || ($methods['can_pay'] ?? true) !== false) {
            throw new RuntimeException('Disabled payment capability changed.');
        }
    } else {
        throw new RuntimeException('Unsupported concurrency action.');
    }
    $result = ['ok' => true];
} catch (UniqueConstraintViolationException) {
    $result = ['ok' => false, 'duplicate' => true];
} catch (Throwable $exception) {
    $result = ['ok' => false, 'class' => get_class($exception), 'message' => $exception->getMessage()];
}
file_put_contents($resultFile, json_encode($result, JSON_THROW_ON_ERROR));
