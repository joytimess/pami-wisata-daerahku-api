<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Favorite extends Model
{
    use HasUuids;

    protected $primaryKey = 'guid';

    protected $fillable = ['user_guid', 'destination_guid'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class, 'destination_guid', 'guid');
    }
}
