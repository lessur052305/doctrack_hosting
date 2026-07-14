<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SubmissionBatch
 * ---------------
 * One row per "Submit Document(s)" form post from an Originator. Every
 * document created in that same request shares this batch's `due_date`
 * and is nested under it in the Approver and Admin SLA dashboards, so
 * reviewers can see at a glance which documents arrived together as one
 * request instead of as unrelated flat rows.
 */
class SubmissionBatch extends Model
{
    protected $table = 'submission_batches';
    protected $primaryKey = 'batch_id';

    protected $fillable = [
        'originator_id', 'due_date',
    ];

    protected $casts = [
        'due_date' => 'datetime',
    ];

    public function originator()
    {
        return $this->belongsTo(User::class, 'originator_id', 'user_id');
    }

    public function documents()
    {
        return $this->hasMany(DocumentRepository::class, 'batch_id', 'batch_id');
    }
}