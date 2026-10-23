<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Trip in the shape the portal expects (src/types/index.ts → Trip). */
class TripResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $cr = $this->cashRequest;
        $started = $this->started_at;
        $completed = $this->completed_at;

        return [
            'id' => $cr?->reference,
            'date' => $completed?->format('Y-m-d') ?? $started?->format('Y-m-d') ?? $cr?->requested_date?->format('Y-m-d'),
            'branchId' => $cr?->branch?->code,
            'type' => $cr?->type === 'deposit' ? 'Deposit' : 'Withdrawal',
            'teamId' => (string) $this->team_id,
            'amount' => (float) ($cr?->amount ?? 0),
            'currency' => $cr?->currency ?? 'MMK',
            'durationMin' => ($started && $completed) ? max(0, $completed->diffInMinutes($started)) : 0,
            'onTime' => (bool) ($this->is_on_time ?? false),
        ];
    }
}
