<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    protected $primaryKey = 'guid';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;
}
