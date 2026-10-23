<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Branch in the shape the portal expects (src/types/index.ts → Branch). */
class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->code,         // portal uses the code as the id
            'code'          => $this->code,
            'name'          => $this->name,
            'address'       => $this->address,
            'type'          => $this->is_hq ? 'HQ' : 'Branch',
            'lat'           => (float) $this->lat,
            'lng'           => (float) $this->lng,
            'radius'        => (int) $this->handover_radius,
            'contactName'   => $this->contact_name,
            'contactMobile' => $this->contact_phone,
            'contactEmail'  => $this->contact_email,
            'active'        => (bool) $this->is_active,
        ];
    }
}
