<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Exactly ONE row per document per stage ("single-assignment routing").
 * When a document enters a stage, it is routed to whichever eligible
 * approver in that category currently has the fewest pending assignments
 * (see WorkflowService::selectApproverForStage()) — never to more than one
 * approver at once, so a document can't be picked up or acted on by two
 * approvers simultaneously.
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
        // Carbon 3's diffInSeconds() returns a float even with the signed
        // ($absolute=false) form; round explicitly rather than let PHP's
        // implicit float->int narrowing throw a deprecation warning.
        return (int) round(now()->diffInSeconds($this->sla_expires_at, false));
    }

    /**
     * Section 3: once escalated, the assignment leaves the approver's own
     * queue — it's now the Admin's to resolve via the SLA override queue.
     */
    public function scopePendingFor($query, $userId)
    {
        return $query->where('user_id', $userId)
            ->where('individual_status', 'pending')
            ->where('escalated_to_admin', false);
    }
}