<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Proof of delivery: confirmed amount, denominations, signature, photos. Table: handover_proofs */
class HandoverProof extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'confirmed_amount' => 'decimal:2',
            'denominations' => 'array',
            'lat' => 'float',
            'lng' => 'float',
            'submitted_at' => 'datetime',
        ];
    }

    public function cashRequest()
    {
        return $this->belongsTo(CashRequest::class);
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function photos()
    {
        return $this->hasMany(HandoverPhoto::class);
    }
}
