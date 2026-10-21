<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One action a role may perform, e.g. "requests.approve". Table: permissions */
class Permission extends Model
{
    protected $guarded = ['id'];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_permission');
    }
}
