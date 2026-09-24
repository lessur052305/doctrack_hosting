<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * DocumentRevision
 * -----------------
 * One immutable snapshot per originator save (Feature: Revision History,
 * Google-Docs-style — see WorkflowService::saveDocumentRevision()).
 * previous_text/new_text are each a full copy of DocumentRepository::
 * ocr_text at that moment, not a chained diff against the prior row — so
 * any single row can be read/diffed in isolation without walking the
 * whole history first (see TextDiffService, which diffs exactly these two
 * columns). Linked via document_revision_annotations to whichever
 * DocumentAnnotation(s) that same save marked resolved, so an approver
 * piling through a long history can find their own entry.
 */
class DocumentRevision extends Model
{
    protected $primaryKey = 'revision_id';

    public $timestamps = false;

    protected $fillable = [
        'document_id', 'revised_by', 'previous_text', 'new_text', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function document()
    {
        return $this->belongsTo(DocumentRepository::class, 'document_id', 'document_id');
    }

    public function revisedBy()
    {
        return $this->belongsTo(User::class, 'revised_by', 'user_id');
    }

    public function annotations()
    {
        return $this->belongsToMany(DocumentAnnotation::class, 'document_revision_annotations', 'revision_id', 'annotation_id');
    }
}
