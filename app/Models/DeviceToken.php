<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DeviceToken extends Model
{
    protected $primaryKey = 'guid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['user_guid', 'session_guid', 'token', 'platform', 'last_seen_at'];

    protected static function booted(): void
    {
        static::creating(fn ($model) => $model->guid ??= (string) Str::uuid());
    }
}
