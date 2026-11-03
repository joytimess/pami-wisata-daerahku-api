<?php

use App\Exceptions\BookingException;
use App\Models\User;
use App\Services\AvatarService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || ! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
    exit(2);
}
[, $directory, $index, $json] = $argv;
$job = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
config(['filesystems.disks.local.root' => $directory.'/files']);
$user = User::findOrFail($job['user']);
$service = new class($directory) extends AvatarService
{
    public function __construct(private string $directory) {}

    protected function downloadGoogle(string $url): string
    {
        touch($this->directory.'/downloading');
        $deadline = microtime(true) + 25;
        while (! file_exists($this->directory.'/manual-done') && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (! file_exists($this->directory.'/manual-done')) {
            throw new RuntimeException('Manual mutation did not finish');
        }
        $file = UploadedFile::fake()->image('google.png');

        return file_get_contents($file->getRealPath());
    }
};
touch($directory.'/ready-'.$index);
$deadline = microtime(true) + 25;
while (! file_exists($directory.'/go') && microtime(true) < $deadline) {
    usleep(10000);
}
if (! file_exists($directory.'/go')) {
    exit(3);
}
try {
    if ($job['kind'] === 'snapshot') {
        Log::shouldReceive('warning')->never();
        $service->snapshotGoogle($user, 'https://lh3.googleusercontent.com/photo');
    } elseif ($job['kind'] === 'manual') {
        $deadline = microtime(true) + 25;
        while (! file_exists($directory.'/downloading') && microtime(true) < $deadline) {
            usleep(10000);
        }
        $service->preference($user, 'default', 0);
        touch($directory.'/manual-done');
    } else {
        $service->upload($user, UploadedFile::fake()->image('custom.png'), 0);
    }
    echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
} catch (BookingException $exception) {
    echo json_encode(['ok' => false, 'code' => $exception->errorCode], JSON_THROW_ON_ERROR);
}
