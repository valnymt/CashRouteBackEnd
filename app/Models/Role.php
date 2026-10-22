<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Super Admin, Maker, Checker (portal) · Driver, Custodian (mobile app). Table: roles */
class Role extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
