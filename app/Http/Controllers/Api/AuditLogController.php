<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;

/** AP-09 Audit Trail: read-only list of every action, newest first. */
class AuditLogController extends Controller
{
    /** GET /api/audit-logs */
    public function index(Request $request)
    {
        abort_unless(
            $request->user()->hasPermission('audit.view'),
            403,
            'You are not allowed to view the audit trail.'
        );

        return AuditLogResource::collection(
            AuditLog::with(['user', 'cashRequest'])
                ->orderByDesc('created_at')
                ->limit(1000)   // Audit grows forever; keep it to the last 1000 for the UI.
                ->get()
        );
    }
}
