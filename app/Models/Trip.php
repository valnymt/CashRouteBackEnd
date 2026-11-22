<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One delivery run for a request. status: assigned | in_transit | completed | cancelled. Table: trips */
class Trip extends Model
{
    protected $guarded = ['id'];
    protected $fillable = [
        // Keep the model's existing fillable fields...
        'accepted_at',
        'current_lat',
        'current_lng',
        'last_ping_at',
        'status',
        'started_at',
    ];

    protected function casts(): array
    {
        return [
            'current_lat' => 'float',
            'current_lng' => 'float',
            'last_ping_at' => 'datetime',
            'assigned_at' => 'datetime',
            'accepted_at' => 'datetime',
            'arrived_pickup_at' => 'datetime',
            'arrived_dropoff_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'is_on_time' => 'boolean',
        ];
    }

    public function cashRequest()
    {
        return $this->belongsTo(CashRequest::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function locations()
    {
        return $this->hasMany(TripLocation::class)->orderBy('recorded_at');
    }
}
