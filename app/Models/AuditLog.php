<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-only record of every action (AP-09). The database itself refuses UPDATE / DELETE
 * on this table, so only ever create rows — use App\Support\Audit::log(). Table: audit_logs
 */
class AuditLog extends Model
{
    const UPDATED_AT = null;   // audit rows are never edited

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['details' => 'array', 'lat' => 'float', 'lng' => 'float', 'created_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function cashRequest()
    {
        return $this->belongsTo(CashRequest::class);
    }
}
