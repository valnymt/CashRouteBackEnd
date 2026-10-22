<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One photo of a handover proof (storage path, not a URL). Table: handover_photos */
class HandoverPhoto extends Model
{
    protected $guarded = ['id'];

    public function proof()
    {
        return $this->belongsTo(HandoverProof::class, 'handover_proof_id');
    }
}
