<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\CashRequest;

/**
 * The one way every module writes the audit trail.
 *
 *   Audit::log('approve', $cashRequest);
 *   Audit::log('reject', $cashRequest, ['reason' => $reason]);
 *   Audit::log('accept', $cashRequest, [], 'mobile', $lat, $lng);
 *   Audit::log('login');                       // not about a request
 */
class Audit
{
    public static function log(
        string $action,
        ?CashRequest $request = null,
        array $details = [],
        string $source = 'web',
        ?float $lat = null,
        ?float $lng = null,
        ?int $userId = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $userId ?? auth()->id(),
            'cash_request_id' => $request?->id,
            'action' => $action,
            'details' => $details ?: null,
            'source' => $source,
            'lat' => $lat,
            'lng' => $lng,
            'ip_address' => request()->ip(),
        ]);
    }
}
