<?php

namespace App\Console\Commands;

use App\Services\AvatarService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupAvatars extends Command
{
    protected $signature = 'avatars:cleanup';

    protected $description = 'Retry deletion of unreferenced profile pictures';

    public function handle(AvatarService $avatars): int
    {
        $failed = false;
        DB::table('avatar_file_cleanups')->where('eligible_at', '<=', now())->orderBy('id')->chunkById(100, function ($rows) use ($avatars, &$failed): void {
            foreach ($rows as $row) {
                if (! $avatars->cleanup($row->path)) {
                    $failed = true;
                }
            }
        });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
