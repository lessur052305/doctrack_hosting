<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DOCUMENT_ASSIGNMENTS — Data Dictionary Table 3.5.4
 * Powers the PARALLEL approval workflow (Process 5.0): when a document
 * enters a stage, every eligible approver for that stage gets their own row
 * here, all sharing the same `sla_expires_at` (computed as 25% of the time
 * remaining until the document's absolute due_date). Whichever approver
 * acts first resolves the stage; there is no hierarchy or fixed order.
 *
 * `user_id` is the approver this specific assignment belongs to.
 * `escalated_to_admin` is flipped independently per row by the
 * workflow:check-parallel-slas scheduled command — one slow approver's
 * missed deadline never affects their on-time parallel peers.
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

            // --- SLA fields (Section 5 / parallel-approval SLA rule) ---
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