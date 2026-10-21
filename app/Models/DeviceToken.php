<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A phone's push-notification token (FCM / APNs). Table: device_tokens */
class DeviceToken extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
