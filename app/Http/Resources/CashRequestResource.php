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
     * no extra table is needed"). For older/seeded requests that have no audit
     * trail, fall back to the lifecycle timestamps on the request + trip so
     * the detail page is never blank.
     */
    private function buildTimeline(): array
    {
        if ($this->auditLogs->isNotEmpty()) {
            return $this->auditLogs->map(function ($log) {
                return [
                    'label' => $this->labelFor($log),
                    'by' => $log->user?->name ?? '—',
                    'at' => $log->created_at?->toIso8601String(),
                ];
            })->values()->all();
        }

        return $this->syntheticTimeline();
    }

    /** Build a plausible timeline from the stored timestamps when audit_logs is empty. */
    private function syntheticTimeline(): array
    {
        $items = [];
        $maker    = $this->creator?->name ?? '—';
        $approver = $this->approver?->name ?? '—';
        $team     = $this->trip?->team?->name;

        $add = function (?string $at, string $label, string $by) use (&$items) {
            if (!$at) return;
            $items[] = ['label' => $label, 'by' => $by, 'at' => $at];
        };

        $add($this->created_at?->toIso8601String(), 'Created', $maker);
        $add($this->approved_at?->toIso8601String(), 'Approved', $approver);
        $add($this->rejected_at?->toIso8601String(), 'Rejected' . ($this->reject_reason ? ' — ' . $this->reject_reason : ''), $approver);
        $add($this->cancelled_at?->toIso8601String(), 'Cancelled' . ($this->cancel_reason ? ' — ' . $this->cancel_reason : ''), $this->cancelled_by_role === 'checker' ? $approver : $maker);

        if ($this->trip) {
            $teamLabel = $team ? "$team assigned" : 'Team assigned';
            $add($this->trip->assigned_at?->toIso8601String(), $teamLabel, $approver);
            $add($this->trip->accepted_at?->toIso8601String(), 'Team accepted', $team ?? '—');
            $add($this->trip->started_at?->toIso8601String(), 'In transit', $team ?? '—');
            $add($this->trip->completed_at?->toIso8601String(), 'Delivered', $team ?? '—');
        } else {
            $add($this->completed_at?->toIso8601String(), 'Delivered', $maker);
        }

        // Chronological, then de-dupe entries sharing a label.
        usort($items, fn ($a, $b) => strcmp($a['at'], $b['at']));
        return $items;
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
