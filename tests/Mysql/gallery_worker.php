<?php

use App\Exceptions\BookingException;
use App\Models\Destination;
use App\Models\User;
use App\Services\DestinationGalleryService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || ! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
    exit(2);
}
[, $ready, $go, $json] = $argv;
$job = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
touch($ready);
$deadline = microtime(true) + 30;
while (! file_exists($go) && microtime(true) < $deadline) {
    usleep(10000);
}
if (! file_exists($go)) {
    exit(3);
}
try {
    $result = app(DestinationGalleryService::class)->save($job['data'], User::findOrFail($job['actor']), Destination::findOrFail($job['destination']));
    echo json_encode(['ok' => true, 'data' => $result], JSON_THROW_ON_ERROR);
} catch (BookingException $exception) {
    echo json_encode(['ok' => false, 'code' => $exception->errorCode], JSON_THROW_ON_ERROR);
}
