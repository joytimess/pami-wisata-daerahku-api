<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class AvatarService
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    public function upload(User $user, UploadedFile $file, int $version): User
    {
        $bytes = $this->normalize(file_get_contents($file->getRealPath()));
        $path = $this->stage($user, $bytes);
        try {
            return $this->change($user, 'custom', $version, $path);
        } finally {
            $this->cleanup($path);
        }
    }

    public function preference(User $user, string $source, int $version): User
    {
        return $this->change($user, $source, $version);
    }

    private function change(User $user, string $source, int $version, ?string $path = null): User
    {
        return DB::transaction(function () use ($user, $source, $version, $path): User {
            if ($path) {
                DB::table('avatar_file_cleanups')->where('path', $path)->lockForUpdate()->firstOrFail();
            }
            $current = User::whereKey($user->guid)->lockForUpdate()->firstOrFail();
            if ($current->avatar_version !== $version) {
                throw new BookingException('The profile picture changed. Refresh and try again.', 'avatar_version_conflict', ['avatar_version' => $current->avatar_version]);
            }
            if ($source === 'google' && ! $current->google_avatar_snapshot_path) {
                throw new BookingException('No Google photo snapshot is available.', 'google_snapshot_unavailable');
            }
            $old = $current->avatar_custom_path;
            $current->avatar_source = $source;
            $current->avatar_manual_choice = true;
            $current->avatar_custom_path = $source === 'custom' ? $path : null;
            $current->avatar_version++;
            $current->save();
            if ($old) {
                DB::afterCommit(fn () => $this->cleanup($old));
            }

            return $current;
        }, 3);
    }

    public function snapshotGoogle(User $user, ?string $url): void
    {
        if ($user->avatar_manual_choice || $user->google_avatar_snapshot_path || ! $url) {
            return;
        }
        $path = null;
        try {
            $bytes = $this->downloadGoogle($url);
            $path = $this->stage($user, $this->normalize($bytes));
            DB::transaction(function () use ($user, $path): void {
                DB::table('avatar_file_cleanups')->where('path', $path)->lockForUpdate()->firstOrFail();
                $current = User::whereKey($user->guid)->lockForUpdate()->firstOrFail();
                if ($current->avatar_manual_choice || $current->google_avatar_snapshot_path || $current->status !== 'active' || $current->role !== 0) {
                    return;
                }
                $current->google_avatar_snapshot_path = $path;
                $current->avatar_source = 'google';
                $current->avatar_version++;
                $current->save();
            }, 3);
        } catch (Throwable) {
            Log::warning('Google avatar snapshot failed; retry on next eligible login.');
        } finally {
            if ($path) {
                $this->cleanup($path);
            }
        }
    }

    protected function downloadGoogle(string $url): string
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        if (($parts['scheme'] ?? '') !== 'https' || ! in_array($host, ['lh3.googleusercontent.com', 'lh4.googleusercontent.com', 'lh5.googleusercontent.com', 'lh6.googleusercontent.com'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new \RuntimeException('Invalid Google photo URL.');
        }
        $address = $this->publicAddress($host);
        $response = Http::connectTimeout(2)->timeout(5)->withoutRedirecting()->withOptions([
            'proxy' => '',
            'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$address]],
            'on_headers' => function (ResponseInterface $response): void {
                if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BYTES) {
                    throw new \RuntimeException('Google photo exceeds size limit.');
                }
            },
            'progress' => function (int $total, int $downloaded): void {
                if ($total > self::MAX_BYTES || $downloaded > self::MAX_BYTES) {
                    throw new \RuntimeException('Google photo exceeds size limit.');
                }
            },
        ])->get($url);
        if (! $response->successful() || ! in_array(strtolower(trim(explode(';', $response->header('Content-Type'))[0])), ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new \RuntimeException('Invalid Google photo response.');
        }

        return $response->body();
    }

    protected function publicAddress(string $host): string
    {
        $addresses = gethostbynamel($host);
        if (! $addresses) {
            throw new \RuntimeException('Google photo host unavailable.');
        }
        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \RuntimeException('Google photo host is not public.');
            }
        }

        return $addresses[0];
    }

    private function normalize(string $bytes): string
    {
        $info = strlen($bytes) <= self::MAX_BYTES ? @getimagesizefromstring($bytes) : false;
        if (! $info || ! in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) || $info[0] * $info[1] > 16000000 || max($info[0], $info[1]) > 8192) {
            throw ValidationException::withMessages(['avatar' => 'Use a valid JPG, PNG or WebP up to 5 MiB and 16 megapixels / 8192 pixels.']);
        }
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            throw ValidationException::withMessages(['avatar' => 'The image cannot be decoded.']);
        }
        $canvas = imagecreatetruecolor(512, 512);
        try {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $side = min(imagesx($image), imagesy($image));
            imagecopyresampled($canvas, $image, 0, 0, (int) ((imagesx($image) - $side) / 2), (int) ((imagesy($image) - $side) / 2), 512, 512, $side, $side);
            ob_start();
            try {
                $encoded = imagepng($canvas);
                $result = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            if (! $encoded || ! $result) {
                throw new \RuntimeException('Avatar encoding failed.');
            }

            return $result;
        } finally {
            imagedestroy($image);
            imagedestroy($canvas);
        }
    }

    private function stage(User $user, string $bytes): string
    {
        $path = 'avatars/'.$user->guid.'/'.Str::uuid().'.png';
        DB::table('avatar_file_cleanups')->insert(['path' => $path, 'eligible_at' => now()->addHour()]);
        try {
            if (! Storage::disk('local')->put($path, $bytes, ['visibility' => 'private'])) {
                throw new \RuntimeException('Avatar storage failed.');
            }
        } catch (Throwable $exception) {
            $this->cleanup($path);
            throw $exception;
        }

        return $path;
    }

    public function cleanup(string $path): bool
    {
        try {
            DB::transaction(function () use ($path): void {
                $record = DB::table('avatar_file_cleanups')->where('path', $path)->lockForUpdate()->first();
                if (! $record || ! str_starts_with($path, 'avatars/')) {
                    return;
                }
                if (User::where('avatar_custom_path', $path)->orWhere('google_avatar_snapshot_path', $path)->exists()) {
                    DB::table('avatar_file_cleanups')->where('id', $record->id)->update(['eligible_at' => now()->addHour()]);

                    return;
                }
                $disk = Storage::disk('local');
                if ($disk->exists($path) && ! $disk->delete($path)) {
                    throw new \RuntimeException('Avatar deletion failed.');
                }
                DB::table('avatar_file_cleanups')->where('id', $record->id)->delete();
            }, 3);

            return true;
        } catch (Throwable) {
            Log::warning('Avatar file cleanup failed; scheduled cleanup will retry.');
            try {
                DB::table('avatar_file_cleanups')->where('path', $path)->update(['eligible_at' => now()->addMinute(), 'attempts' => DB::raw('attempts + 1')]);
            } catch (Throwable) {
                // The durable record remains eligible when the database is temporarily unavailable.
            }

            return false;
        }
    }
}
