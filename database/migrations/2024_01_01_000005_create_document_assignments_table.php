<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DOCUMENT_ASSIGNMENTS — Data Dictionary Table 3.5.4
 * Powers the SINGLE-ASSIGNMENT, LOAD-BALANCED approval workflow
 * (Process 5.0): when a document enters a stage, it is routed to exactly
 * ONE eligible approver for that stage — whichever eligible approver
 * currently has the fewest pending assignments — so a document is never
 * visible in more than one approver's queue at the same time.
 *
 * `user_id` is the approver this assignment belongs to.
 * `escalated_to_admin` is flipped by the workflow:check-parallel-slas
 * scheduled command once this assignment's own `sla_expires_at`
 * (computed as 25% of the time remaining until the document's absolute
 * due_date) has passed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_assignments', function (Blueprint $table) {
            $table->id('assignment_id');
            $table->foreignId('document_id')->constrained('document_repository', 'document_id')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->foreignId('stage_id')->constrained('workflow_stages', 'stage_id');

            $table->dateTime('due_date')->nullable(); // mirrored from document_repository.due_date
            $table->tinyInteger('priority_rank')->default(2); // 1=Urgent, 2=Normal, 3=Low

            $table->enum('individual_status', ['pending', 'approved', 'rejected', 'auto_approved'])
                ->default('pending');

            $table->text('comments')->nullable();

            // --- SLA fields (Section 5 / approver SLA rule) ---
            $table->dateTime('sla_expires_at')->nullable();
            $table->timestamp('admin_override_at')->nullable();
            $table->foreignId('admin_override_by')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->boolean('escalated_to_admin')->default(false);
            $table->boolean('auto_approved')->default(false);

            $table->timestamp('acted_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'individual_status']);
            $table->index(['sla_expires_at', 'individual_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_assignments');
    }
}; 