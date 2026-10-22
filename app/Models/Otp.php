<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Handover code for pickup / dropoff. The code itself is only stored hashed. Table: otps */
class Otp extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'verified_at' => 'datetime', 'attempts' => 'integer'];
    }

    public function cashRequest()
    {
        return $this->belongsTo(CashRequest::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function attemptLogs()
    {
        return $this->hasMany(OtpAttempt::class);
    }
}
