<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One typed OTP (right or wrong). Only has attempted_at, no created_at/updated_at. Table: otp_attempts */
class OtpAttempt extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_success' => 'boolean', 'lat' => 'float', 'lng' => 'float', 'attempted_at' => 'datetime'];
    }

    public function otp()
    {
        return $this->belongsTo(Otp::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
