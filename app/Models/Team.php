<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Delivery team (1 driver + 2 custodians). Must be confirmed before it can be assigned.
 * status: available | on_trip | offline. Table: teams
 */
class Team extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_confirmed' => 'boolean', 'confirmed_at' => 'datetime'];
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'team_members')->withTimestamps();
    }

    public function trips()
    {
        return $this->hasMany(Trip::class);
    }

    public function releaseIfIdle(): void
    {
        static::whereKey($this->getKey())
            ->where('status', 'on_trip')
            ->whereDoesntHave('trips', fn ($query) => $query->whereIn('status', ['assigned', 'in_transit']))
            ->update(['status' => 'available']);
    }

    public function eligibleLeader()
    {
        return $this->members()->whereKey($this->leader_user_id)
            ->where('users.is_active', true)->where('users.is_delivery_member', true)
            ->whereHas('role', fn ($query) => $query->where('name', 'Custodian'));
    }

    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
