<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Branch office or the HQ cash centre (is_hq). Table: branches */
class Branch extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_hq' => 'boolean',
            'is_active' => 'boolean',
            'lat' => 'float',
            'lng' => 'float',
            'handover_radius' => 'integer',
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
