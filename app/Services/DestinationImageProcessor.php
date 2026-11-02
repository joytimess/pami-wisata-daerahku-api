<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DestinationImageProcessor
{
    /** @return array<string, mixed> */
    public function process(UploadedFile $file): array
    {
        $info = @getimagesize($file->getRealPath());
        if (! $info || ! in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) || $file->getSize() > 5 * 1024 * 1024 || $info[0] * $info[1] > 24000000 || max($info[0], $info[1]) > 12000) {
            throw ValidationException::withMessages(['images' => 'Invalid image or dimensions exceed 24 megapixels / 12000 pixels.']);
        }
        $image = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        if ($image === false) {
            throw ValidationException::withMessages(['images' => 'The image cannot be decoded.']);
        }
        $metadata = [];
        try {
            if ($info['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
                $orientation = @exif_read_data($file->getRealPath())['Orientation'] ?? 1;
                if (in_array($orientation, [2, 4, 5, 7], true)) {
                    imageflip($image, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
                }
                $angle = match ($orientation) {
                    3 => 180, 6, 7 => -90, 5, 8 => 90, default => 0
                };
                if ($angle !== 0) {
                    $rotated = imagerotate($image, $angle, 0);
                    imagedestroy($image);
                    $image = $rotated;
                }
            }
            $guid = (string) Str::uuid();
            $disk = config('filesystems.default');
            $metadata = ['guid' => $guid, 'storage_disk' => $disk];
            $webp = function_exists('imagewebp') && (imagetypes() & IMG_WEBP);
            foreach (['thumbnail' => 480, 'display' => 1280, 'fullscreen' => 2048] as $variant => $limit) {
                $ratio = min(1, $limit / max(imagesx($image), imagesy($image)));
                $width = max(1, (int) round(imagesx($image) * $ratio));
                $height = max(1, (int) round(imagesy($image) * $ratio));
                $scaled = imagecreatetruecolor($width, $height);
                imagealphablending($scaled, false);
                imagesavealpha($scaled, true);
                imagecopyresampled($scaled, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
                ob_start();
                try {
                    $encoded = $webp ? imagewebp($scaled, null, $variant === 'fullscreen' ? 90 : 82) : imagepng($scaled);
                    $bytes = ob_get_contents();
                } finally {
                    ob_end_clean();
                    imagedestroy($scaled);
                }
                if (! $encoded || ! $bytes) {
                    throw new \RuntimeException('Image encoding failed.');
                }
                $path = 'destination-gallery/'.$guid.'/'.$variant.($webp ? '.webp' : '.png');
                $metadata[$variant.'_path'] = $path;
                DB::table('destination_image_cleanups')->insert(['storage_disk' => $disk, 'path' => $path, 'eligible_at' => now()->addDay()]);
                if (! Storage::disk($disk)->put($path, $bytes)) {
                    throw new \RuntimeException('Image storage failed.');
                }
                $metadata[$variant.'_path'] = $path;
            }

            return $metadata + ['width' => $width, 'height' => $height, 'mime_type' => $webp ? 'image/webp' : 'image/png', 'bytes' => strlen($bytes)];
        } catch (\Throwable $exception) {
            $this->discard($metadata);
            throw $exception;
        } finally {
            imagedestroy($image);
        }
    }

    /** @param array<string, mixed> $image */
    public function discard(array $image): void
    {
        foreach (['thumbnail_path', 'display_path', 'fullscreen_path'] as $field) {
            if (isset($image[$field])) {
                $query = DB::table('destination_image_cleanups')->where('storage_disk', $image['storage_disk'])->where('path', $image[$field]);
                try {
                    $disk = Storage::disk($image['storage_disk']);
                    if (! $disk->exists($image[$field]) || $disk->delete($image[$field])) {
                        $query->delete();

                        continue;
                    }
                } catch (\Throwable $exception) {
                    report($exception);
                }
                $query->update(['eligible_at' => now()]);
            }
        }
    }
}
