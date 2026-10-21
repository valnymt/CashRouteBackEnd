<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One GPS ping from the delivery app. Only has recorded_at, no created_at/updated_at. Table: trip_locations */
class TripLocation extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float', 'accuracy_m' => 'float', 'recorded_at' => 'datetime'];
    }

    public function trip()
    {
        return $this->belongsTo(Trip::class);
    }
}
