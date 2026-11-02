<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Facility extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'guid';

    protected $fillable = ['name', 'slug', 'icon_key', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected static function booted(): void
    {
        static::saving(function (Facility $facility) {
            $facility->name = Str::squish($facility->name);
            $facility->name_key = Str::lower($facility->name);
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }

    public function destinations(): BelongsToMany
    {
        return $this->belongsToMany(Destination::class, 'destination_facilities', 'facility_guid', 'destination_guid', 'guid', 'guid')->withTimestamps();
    }
}
