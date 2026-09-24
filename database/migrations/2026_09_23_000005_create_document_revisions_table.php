<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revision History (Feature: Google-Docs-style before/after history for
 * the originator's "editable document" saves — see WorkflowService::
 * saveDocumentRevision()). One row per save, each a full self-contained
 * snapshot (previous_text/new_text), not a chained diff against the prior
 * row — so any single revision can be read/diffed in isolation without
 * walking the whole history first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_revisions', function (Blueprint $table) {
            $table->id('revision_id');
            $table->foreignId('document_id')->constrained('document_repository', 'document_id')->cascadeOnDelete();
            $table->foreignId('revised_by')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->longText('previous_text');
            $table->longText('new_text');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['document_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_revisions');
    }
};
