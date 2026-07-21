<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an Admin review a stage the SYSTEM auto-approved (not one they
 * personally acted on) — confirming it was fine, or disputing it. Disputing
 * does not reverse the approval (the workflow has no "reopen" mechanism —
 * see WorkflowService::completeStage()); it sets the parent document's
 * disputed_at (see document_repository) and notifies the originator that a
 * corrected resubmission is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_assignments', function (Blueprint $table) {
            $table->timestamp('admin_reviewed_at')->nullable()->after('auto_approved');
            $table->foreignId('admin_reviewed_by')->nullable()->after('admin_reviewed_at')
                ->constrained('users', 'user_id')->nullOnDelete();
            $table->text('admin_review_note')->nullable()->after('admin_reviewed_by');
            $table->enum('admin_review_outcome', ['confirmed', 'disputed'])->nullable()->after('admin_review_note');
        });
    }

    public function down(): void
    {
        Schema::table('document_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('admin_reviewed_by');
            $table->dropColumn(['admin_reviewed_at', 'admin_review_note', 'admin_review_outcome']);
        });
    }
};
