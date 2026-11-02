<?php

namespace App\Console\Commands;

use App\Models\Destination;
use App\Services\DestinationImageProcessor;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BackfillDestinationImages extends Command
{
    protected $signature = 'destinations:backfill-images';

    protected $description = 'Create optimized legacy galleries without changing or deleting original files';

    public function handle(DestinationImageProcessor $processor): int
    {
        $failed = 0;
        Destination::whereNotNull('image_path')->whereDoesntHave('images')->orderBy('guid')->chunkById(50, function ($destinations) use ($processor, &$failed): void {
            foreach ($destinations as $destination) {
                $temporary = tempnam(sys_get_temp_dir(), 'gallery');
                $image = null;
                try {
                    $disk = Storage::disk();
                    if (! $disk->exists($destination->image_path)) {
                        throw new \RuntimeException('Legacy image missing.');
                    }
                    file_put_contents($temporary, $disk->get($destination->image_path));
                    $image = $processor->process(new UploadedFile($temporary, basename($destination->image_path), test: true));
                    DB::transaction(function () use ($destination, $image, $processor): void {
                        $locked = Destination::whereKey($destination->guid)->lockForUpdate()->firstOrFail();
                        if ($locked->images()->exists()) {
                            $processor->discard($image);

                            return;
                        }
                        $locked->images()->create($image + ['sequence' => 1]);
                        $locked->increment('gallery_version');
                        foreach (['thumbnail_path', 'display_path', 'fullscreen_path'] as $field) {
                            DB::table('destination_image_cleanups')->where('storage_disk', $image['storage_disk'])->where('path', $image[$field])->delete();
                        }
                    }, 3);
                    $this->info($destination->guid.' backfilled');
                } catch (\Throwable $exception) {
                    if ($image) {
                        $processor->discard($image);
                    }
                    $failed++;
                    $this->error($destination->guid.': '.$exception->getMessage());
                } finally {
                    @unlink($temporary);
                }
            }
        }, 'guid');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
