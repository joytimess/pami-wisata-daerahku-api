<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Destination extends Model
{
    use HasUuids;

    protected $primaryKey = 'guid';

    protected $attributes = ['is_active' => true, 'currency' => 'IDR'];

    protected $fillable = ['destination_category_guid', 'name', 'location', 'description', 'ticket_price', 'currency', 'opening_hours', 'timezone', 'image_path', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'ticket_price' => 'integer'];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DestinationCategory::class, 'destination_category_guid', 'guid');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'destination_guid', 'guid');
    }
}
