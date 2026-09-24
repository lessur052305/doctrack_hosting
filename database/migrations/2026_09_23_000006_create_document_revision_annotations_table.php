<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a DocumentRevision to whichever open DocumentAnnotation(s) that
 * same save marked resolved (see WorkflowService::saveDocumentRevision()) —
 * so an approver piling through a long revision history can find the
 * exact entry that addressed their own flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_revision_annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('revision_id')->constrained('document_revisions', 'revision_id')->cascadeOnDelete();
            $table->foreignId('annotation_id')->constrained('document_annotations', 'annotation_id')->cascadeOnDelete();

            $table->unique(['revision_id', 'annotation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_revision_annotations');
    }
};
