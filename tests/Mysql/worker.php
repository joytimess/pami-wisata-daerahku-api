<?php

/**
 * Child process used by the MySQL concurrency tests: boots the real application, waits on a start
 * barrier so every worker hits the database at once, runs one service call and prints a JSON result.
 *
 * Usage: php worker.php <ready-file> <go-file> <job-json>
 */

use App\Models\Destination;
use App\Models\User;
use App\Services\BookingService;
use App\Services\QuotaService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! str_ends_with((string) config('database.connections.mysql.database'), '_test') || config('database.default') !== 'mysql') {
    fwrite(STDERR, "Refusing to run outside the dedicated *_test MySQL database.\n");
    exit(2);
}

[, $readyFile, $goFile, $json] = $argv;
$job = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
$user = User::findOrFail($job['user']);
$bookings = app(BookingService::class);
touch($readyFile);
$deadline = microtime(true) + 60;
while (! file_exists($goFile) && microtime(true) < $deadline) {
    usleep(500);
}

try {
    $result = match ($job['action']) {
        'create' => $bookings->create($user, $job['payload'] + ['idempotency_key' => $job['key']]),
        'cancel' => $bookings->cancel($user, $job['guid']),
        'decide' => $bookings->decide($user, $job['guid'], $job['approve']),
        'quota' => app(QuotaService::class)->upsert(Destination::findOrFail($job['destination']), $job['fields']),
    };
    echo json_encode(['ok' => true, 'guid' => $result->guid ?? null, 'status' => $result->status ?? null, 'capacity' => $result->capacity ?? null]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'class' => class_basename($e), 'code' => $e->errorCode ?? null, 'message' => $e->getMessage()]);
}
