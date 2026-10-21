<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * In-app notification (read_at NULL = unread). Named UserNotification because User's
 * Notifiable trait already uses the name notifications(). Table: notifications
 */
class UserNotification extends Model
{
    protected $table = 'notifications';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function cashRequest()
    {
        return $this->belongsTo(CashRequest::class);
    }
}
