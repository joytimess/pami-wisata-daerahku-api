<?php

use App\Models\User;
use App\Services\BookingService;
use App\Services\DemoPaymentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! env('MYSQL_CONCURRENCY_TESTS') || config('database.default') !== 'mysql'
    || ! preg_match('/^pami_phase1b_demo_[a-z0-9]{12}_test$/D', (string) config('database.connections.mysql.database'))) {
    exit(2);
}
config(['operations.demo_payments_enabled' => true, 'operations.doku_enabled' => false]);
Http::preventStrayRequests();
[, $ready, $go, $json, $resultFile] = $argv;
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
    $owner = isset($job['user_guid']) ? User::findOrFail($job['user_guid']) : null;
    $data = match ($job['action']) {
        'initiate' => app(DemoPaymentService::class)->initiate($owner, $job['booking_guid'], $job['input']),
        'simulate' => app(DemoPaymentService::class)->simulate($owner, $job['booking_guid'], $job['input']),
        'cancel' => app(BookingService::class)->cancel($owner, $job['booking_guid']),
        'expire' => app(BookingService::class)->expireDue(),
        default => throw new RuntimeException('Unsupported demo concurrency action.'),
    };
    $result = ['ok' => true, 'data' => $data];
} catch (Throwable $exception) {
    $result = ['ok' => false, 'code' => $exception->errorCode ?? null, 'class' => get_class($exception)];
}
file_put_contents($resultFile, json_encode($result, JSON_THROW_ON_ERROR));
