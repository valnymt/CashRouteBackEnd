<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Audit entry in the shape the portal expects (src/types/index.ts → AuditLog). */
class AuditLogResource extends JsonResource
{
    /** DB action string → portal event label. */
    private const EVENT = [
        'create'       => 'Create',
        'edit'         => 'Edit',
        'approve'      => 'Approve',
        'reject'       => 'Reject',
        'assign'       => 'Assign',
        'cancel'       => 'Cancel',
        'in_transit'   => 'In Transit',
        'completed'    => 'Delivered',
        'delivered'    => 'Delivered',
        'accept'       => 'In Transit',
        'otp.verified' => 'In Transit',
    ];

    public function toArray(Request $request): array
    {
        return [
            'id'        => 'A-' . str_pad((string) $this->id, 4, '0', STR_PAD_LEFT),
            'at'        => $this->created_at?->format('Y-m-d\TH:i:s.v\Z'),
            'event'     => self::EVENT[$this->action] ?? ucfirst(str_replace('.', ' ', $this->action)),
            'requestId' => $this->cashRequest?->reference ?? '',
            'userId'    => $this->user?->employee_id ?? '',
            'source'    => $this->source === 'mobile' ? 'Mobile app' : 'Web portal',
            'gps'       => ($this->lat !== null && $this->lng !== null) ? "{$this->lat}, {$this->lng}" : null,
        ];
    }
}
