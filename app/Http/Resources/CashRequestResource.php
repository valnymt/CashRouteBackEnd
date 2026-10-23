<?php

namespace App\Http\Resources;

use App\Support\CompletionWindow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Cash request in the shape the portal expects (src/types/index.ts → CashRequest). */
class CashRequestResource extends JsonResource
{
    /** Enum → portal label */
    private const STATUS = [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'assigned' => 'Assigned',
        'in_transit' => 'In Transit',
        'completed' => 'Delivered',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
    ];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->reference,
            'type' => $this->type === 'deposit' ? 'Deposit' : 'Withdrawal',
            'branchId' => $this->branch?->code,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'date' => $this->requested_date?->format('Y-m-d'),
            'slot' => CompletionWindow::toLabel($this->complete_within),
            'remarks' => $this->remarks ?? '',
            'createdBy' => $this->creator?->employee_id,
            'createdAt' => $this->created_at?->toIso8601String(),
            'status' => self::STATUS[$this->status] ?? ucfirst($this->status),
            'teamId' => $this->trip ? (string) $this->trip->team_id : null,
            'rejectReason' => $this->reject_reason,
            'cancelReason' => $this->cancel_reason,
            'cancelledBy' => $this->cancelled_by_role ? ucfirst($this->cancelled_by_role) : null,
            'timeline' => $this->buildTimeline(),
        ];
    }

    /**
     * The timeline the portal shows is built from audit_logs — see the DB design
     * doc ("the request timeline shown in the UI is built from audit_logs, so
     * no extra table is needed").
     */
    private function buildTimeline(): array
    {
        return $this->auditLogs->map(function ($log) {
            return [
                'label' => $this->labelFor($log),
                'by' => $log->user?->name ?? '—',
                'at' => $log->created_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    private function labelFor($log): string
    {
        $details = $log->details ?? [];

        return match ($log->action) {
            'create' => 'Created',
            'edit' => 'Edited by Maker',
            'approve' => 'Approved',
            'reject' => 'Rejected — ' . ($details['reason'] ?? ''),
            'assign' => ($details['team'] ?? 'Team') . ' assigned',
            'cancel' => ($details['by'] ?? 'maker') === 'checker'
                ? 'Cancelled by Checker' . (isset($details['reason']) ? ' — ' . $details['reason'] : '')
                : 'Cancelled by Maker' . (isset($details['reason']) ? ' — ' . $details['reason'] : ''),
            'accept' => 'Team accepted',
            'in_transit' => 'In transit',
            'completed' => 'Delivered',
            'otp.verified' => 'OTP verified · ' . ($details['stage'] ?? ''),
            default => ucfirst(str_replace('.', ' ', $log->action)),
        };
    }
}
