<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Deposit (branch → HQ) or withdrawal (HQ → branch) request.
 * status: pending | approved | assigned | in_transit | completed | rejected | cancelled.
 * Table: cash_requests (not "requests": Laravel already has a Request class).
 */
class CashRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'denominations' => 'array',
            'requested_date' => 'date',
            'due_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function trip()
    {
        return $this->hasOne(Trip::class);
    }

    public function otps()
    {
        return $this->hasMany(Otp::class);
    }

    public function handoverProofs()
    {
        return $this->hasMany(HandoverProof::class);
    }

    /** The request's timeline in the portal. */
    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class)->orderBy('created_at');
    }
}
