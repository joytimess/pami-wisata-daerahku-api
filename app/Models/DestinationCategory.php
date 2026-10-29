<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DestinationCategory extends Model
{
    use HasUuids;

    protected $primaryKey = 'guid';

    protected $attributes = ['is_active' => true];

    protected $fillable = ['name', 'slug', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }
}
