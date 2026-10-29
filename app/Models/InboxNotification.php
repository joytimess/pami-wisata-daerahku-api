<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class InboxNotification extends Model
{
    protected $table = 'notifications';

    protected $primaryKey = 'guid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['guid'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn ($model) => $model->guid ??= (string) Str::uuid());
    }
}
