<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per approver PER STAGE ("parallel assignment"). When a document
 * enters a stage, every eligible approver for that category gets their own
 * row here, all sharing the same computed sla_expires_at. There is no
 * hierarchy — whichever approver acts first resolves the stage, and the
 * remaining sibling rows for that document+stage are auto-closed to match
 * (see WorkflowService::completeStage()).
 */
class DocumentAssignment extends Model
{
    protected $table = 'document_assignments';
    protected $primaryKey = 'assignment_id';

    protected $fillable = [
        'document_id', 'user_id', 'stage_id', 'due_date', 'priority_rank',
        'individual_status', 'comments', 'sla_expires_at', 'admin_override_at',
        'admin_override_by', 'escalated_to_admin', 'auto_approved', 'acted_at',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'sla_expires_at' => 'datetime',
        'admin_override_at' => 'datetime',
        'acted_at' => 'datetime',
        'escalated_to_admin' => 'boolean',
        'auto_approved' => 'boolean',
    ];

    public function document()
    {
        return $this->belongsTo(DocumentRepository::class, 'document_id', 'document_id');
    }

    /**
     * Relationship method kept as `approver()` for readability throughout
     * the app (views, controllers) even though the underlying column is
     * named `user_id` per the required schema.
     */
    public function approver()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function stage()
    {
        return $this->belongsTo(WorkflowStage::class, 'stage_id', 'stage_id');
    }

    public function adminOverrideBy()
    {
        return $this->belongsTo(User::class, 'admin_override_by', 'user_id');
    }

    /** Seconds remaining before SLA breach — used for the countdown UI. */
    public function getSecondsRemainingAttribute(): ?int
    {
        if (!$this->sla_expires_at) {
            return null;
        }
        return now()->diffInSeconds($this->sla_expires_at, false);
    }

    public function scopePendingFor($query, $userId)
    {
        return $query->where('user_id', $userId)->where('individual_status', 'pending');
    }
}