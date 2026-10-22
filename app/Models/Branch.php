<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $fillable = [
        'code',
        'name',
        'address',
        'lat',
        'lng',
        'handover_radius',
        'contact_name',
        'contact_phone',
        'contact_email',
        'is_hq',
        'is_active',
    ];
}
