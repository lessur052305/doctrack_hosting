<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SlaViolation
 * ------------
 * Section 4: SLA Violation & Accountability. Logged the moment an
 * assignment's SLA window is detected as breached (see
 * CheckParallelSlas). stage_name is a denormalized snapshot rather than a
 * stage_id FK so historical violations stay meaningful even after a stage
 * is later renamed/archived/deleted.
 */
class SlaViolation extends Model
{
    protected $table = 'sla_violations';
    protected $primaryKey = 'violation_id';
    public $timestamps = false;

    protected $fillable = [
        'document_id', 'approver_id', 'violation_timestamp', 'duration_overdue', 'stage_name',
    ];

    protected $casts = [
        'violation_timestamp' => 'datetime',
    ];

    public function document()
    {
        return $this->belongsTo(DocumentRepository::class, 'document_id', 'document_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id', 'user_id');
    }
}
