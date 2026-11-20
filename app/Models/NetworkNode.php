<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NetworkNode extends Model
{
    protected $fillable = [
        'identifier',
        'name',
        'type',
        'parent_identifier',
        'latitude',
        'longitude',
    ];
}