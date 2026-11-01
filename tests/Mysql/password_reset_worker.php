<?php

use App\Jobs\SendPasswordResetOtp;
use App\Services\PasswordResetOtpService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || ! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
    exit(2);
}
[, $ready, $go, $json] = $argv;
$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
Queue::fake([SendPasswordResetOtp::class]);
touch($ready);
$deadline = microtime(true) + 30;
while (! file_exists($go) && microtime(true) < $deadline) {
    usleep(10000);
}
if (! file_exists($go)) {
    exit(3);
}
if ($data['action'] === 'request') {
    $request = Request::create('/api/v1/auth/forgot-password', 'POST', ['email' => $data['email']], server: ['REMOTE_ADDR' => '192.0.2.123', 'HTTP_ACCEPT' => 'application/json']);
    $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
    echo json_encode(['status' => $response->getStatusCode()]);
    exit;
}
try {
    $service = app(PasswordResetOtpService::class);
    $result = match ($data['action']) {
        'issue' => $service->issue($data['email']),
        'verify' => $service->verify($data['email'], $data['otp']),
        'reset' => $service->reset($data['payload']),
    };
    echo json_encode(['ok' => true, 'result' => $result]);
} catch (ValidationException) {
    echo json_encode(['ok' => false]);
}
