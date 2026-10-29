<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DestinationQuota extends Model
{
    use HasUuids;

    protected $primaryKey = 'guid';

    protected $fillable = ['destination_guid', 'visit_date', 'capacity', 'booking_opens_at', 'booking_closes_at'];

    protected function casts(): array
    {
        return ['visit_date' => 'date:Y-m-d', 'capacity' => 'integer', 'booking_opens_at' => 'datetime', 'booking_closes_at' => 'datetime'];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }
}
