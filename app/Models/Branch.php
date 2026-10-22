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

    protected function casts(): array
    {
        return [
            'is_hq'            => 'boolean',
            'is_active'        => 'boolean',
            'lat'              => 'float',
            'lng'              => 'float',
            'handover_radius'  => 'integer',
        ];
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function cashRequests()
    {
        return $this->hasMany(CashRequest::class);
    }
}
