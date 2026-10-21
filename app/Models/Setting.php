<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key / value settings (OTP expiry, GPS interval, stale threshold …). Primary key is `key`,
 * only updated_at. Read one with Setting::valueOf('otp_expiry_seconds', 300). Table: settings
 */
class Setting extends Model
{
    const CREATED_AT = null;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public static function valueOf(string $key, mixed $default = null): mixed
    {
        return static::find($key)?->value ?? $default;
    }
}
