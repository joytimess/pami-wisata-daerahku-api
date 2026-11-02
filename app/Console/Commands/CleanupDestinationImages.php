<?php

namespace App\Console\Commands;

use App\Models\Destination;
use App\Models\DestinationImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CleanupDestinationImages extends Command
{
    protected $signature = 'destinations:cleanup-images';

    protected $description = 'Retry committed gallery cleanup and expired unreferenced staged images';

    public function handle(): int
    {
        $failed = 0;
        DB::table('destination_image_cleanups')->where('eligible_at', '<=', now())->orderBy('id')->chunkById(100, function ($rows) use (&$failed): void {
            foreach ($rows as $row) {
                try {
                    $referenced = DestinationImage::where('storage_disk', $row->storage_disk)->where(function ($query) use ($row): void {
                        $query->where('thumbnail_path', $row->path)->orWhere('display_path', $row->path)->orWhere('fullscreen_path', $row->path);
                    })->exists() || Destination::where('image_path', $row->path)->exists();
                    // Only generated derivatives are owned by this cleaner; legacy originals are retained.
                    if (! $referenced && str_starts_with($row->path, 'destination-gallery/')) {
                        $disk = Storage::disk($row->storage_disk);
                        if ($disk->exists($row->path) && ! $disk->delete($row->path)) {
                            throw new \RuntimeException('Image deletion failed.');
                        }
                    }
                    DB::table('destination_image_cleanups')->where('id', $row->id)->delete();
                } catch (\Throwable $exception) {
                    $failed++;
                    DB::table('destination_image_cleanups')->where('id', $row->id)->increment('attempts');
                    $this->error('Cleanup '.$row->id.' failed: '.$exception->getMessage());
                }
            }
        });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
