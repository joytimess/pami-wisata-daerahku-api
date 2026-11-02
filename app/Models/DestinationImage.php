<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DestinationImage extends Model
{
    use HasUuids;

    protected $primaryKey = 'guid';

    protected $fillable = ['guid', 'destination_guid', 'sequence', 'storage_disk', 'thumbnail_path', 'display_path', 'fullscreen_path', 'width', 'height', 'mime_type', 'bytes'];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'width' => 'integer', 'height' => 'integer', 'bytes' => 'integer'];
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class, 'destination_guid', 'guid');
    }
}
