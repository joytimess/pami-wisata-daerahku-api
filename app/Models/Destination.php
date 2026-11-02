<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Destination extends Model
{
    use HasUuids;

    protected $primaryKey = 'guid';

    protected $with = ['images'];

    protected $attributes = ['is_active' => true, 'currency' => 'IDR'];

    protected $fillable = ['destination_category_guid', 'name', 'location', 'description', 'ticket_price', 'currency', 'opening_hours', 'timezone', 'image_path', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'ticket_price' => 'integer', 'gallery_version' => 'integer'];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DestinationCategory::class, 'destination_category_guid', 'guid');
    }

    public function images(): HasMany
    {
        return $this->hasMany(DestinationImage::class, 'destination_guid', 'guid')->orderBy('sequence');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'destination_guid', 'guid');
    }

    public function facilities(): BelongsToMany
    {
        return $this->belongsToMany(Facility::class, 'destination_facilities', 'destination_guid', 'facility_guid', 'guid', 'guid')->withTimestamps();
    }

    public function activeFacilities(): BelongsToMany
    {
        return $this->facilities()->where('facilities.is_active', true)->orderBy('facilities.name')->orderBy('facilities.guid');
    }
}
