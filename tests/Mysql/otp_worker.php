<?php

use App\Jobs\SendEmailVerificationOtp;
use App\Services\EmailVerificationOtpService;
use Illuminate\Contracts\Console\Kernel;
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
Queue::fake([SendEmailVerificationOtp::class]);
touch($ready);
$deadline = microtime(true) + 30;
while (! file_exists($go) && microtime(true) < $deadline) {
    usleep(10000);
}
if (! file_exists($go)) {
    exit(3);
}
try {
    $service = app(EmailVerificationOtpService::class);
    if ($data['action'] === 'verify') {
        $service->verify($data['email'], $data['otp']);
    } else {
        $service->resend($data['email']);
    }
    echo json_encode(['ok' => true]);
} catch (ValidationException) {
    echo json_encode(['ok' => false]);
}
