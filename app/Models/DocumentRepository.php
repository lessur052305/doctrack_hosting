<?php

namespace App\Models;

use App\Events\DocumentStatusChanged;
use Illuminate\Database\Eloquent\Model;

class DocumentRepository extends Model
{
    protected $table = 'document_repository';
    protected $primaryKey = 'document_id';

    /**
     * Broadcasts DocumentStatusChanged over Reverb whenever global_status
     * OR disputed_at actually changes, from wherever it changes — a single
     * hook here instead of manually firing the event at every call site
     * that touches either column, so a future new call site can't silently
     * forget to push the update live. disputed_at is included alongside
     * global_status because AdminController::reviewAutoApproval()'s
     * dispute path deliberately never touches global_status (there's no
     * "reopen" path to reverse an auto-approval — see that method's
     * docblock), so without this the originator would only see a dispute
     * via the ~5-10s polling fallback instead of instantly like every
     * other status change.
     */
    protected static function booted(): void
    {
        static::updated(function (self $document) {
            if ($document->wasChanged('global_status') || $document->wasChanged('disputed_at')) {
                event(new DocumentStatusChanged($document));
            }
        });
    }

    protected $fillable = [
        'originator_id', 'batch_id', 'model_id', 'title', 'file_path', 'original_filename',
        'mime_type', 'ocr_text', 'used_ocr_fallback', 'ml_category', 'ml_confidence',
        'is_validated', 'validation_errors', 'due_date', 'global_status',
        'previous_version_id', 'version_number', 'is_legacy_import', 'disputed_at',
    ];

    protected $casts = [
        'validation_errors' => 'array',
        'is_validated' => 'boolean',
        'used_ocr_fallback' => 'boolean',
        'is_legacy_import' => 'boolean',
        'due_date' => 'datetime',
        'upload_date' => 'datetime',
        'disputed_at' => 'datetime',
    ];

    // Every state a document can be in — mirrors Section 5 state machine.
    public const STATES = ['processing', 'classified_validated', 'approved', 'auto_approved', 'rejected'];

    /** What <x-status-badge> should show — 'disputed' overrides the underlying global_status without replacing it. */
    public function getDisplayStatusAttribute(): string
    {
        return $this->disputed_at ? 'disputed' : $this->global_status;
    }

    public function originator()
    {
        return $this->belongsTo(User::class, 'originator_id', 'user_id');
    }

    /**
     * The submission this document was uploaded alongside (nullable — older
     * documents predate batching, and are treated as a single-document
     * container of their own in the grouped dashboards).
     */
    public function batch()
    {
        return $this->belongsTo(SubmissionBatch::class, 'batch_id', 'batch_id');
    }

    public function model()
    {
        return $this->belongsTo(MlModelRepository::class, 'model_id', 'model_id');
    }

    public function assignments()
    {
        return $this->hasMany(DocumentAssignment::class, 'document_id', 'document_id')
            ->orderBy('stage_id');
    }

    public function currentAssignment()
    {
        return $this->hasOne(DocumentAssignment::class, 'document_id', 'document_id')
            ->where('individual_status', 'pending')
            ->orderBy('stage_id');
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class, 'document_id', 'document_id')->orderByDesc('timestamp');
    }

    /** The document this one was resubmitted to revise, if any. */
    public function previousVersion()
    {
        return $this->belongsTo(self::class, 'previous_version_id', 'document_id');
    }

    /** The resubmission that superseded this document, if one exists yet. */
    public function nextVersion()
    {
        return $this->hasOne(self::class, 'previous_version_id', 'document_id');
    }

    public function scopeForOriginator($query, $userId)
    {
        return $query->where('originator_id', $userId);
    }
}