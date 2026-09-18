<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * needs_approver / needs_approver_at powered the old Unassigned Documents
 * module (a pending seat with nobody eligible, parked in a queue for an
 * Admin to decide or wait out its own SLA deadline) — see the original
 * add_needs_approver_to_document_assignments migration. That module was
 * removed in favor of auto-approving such a seat immediately (see
 * WorkflowService::assignStage()/autoApproveNoEligibleApprover()), so
 * nothing writes or reads these columns anymore. The historical concept
 * itself ('needs_approver' as an audit-log action_type) lives on for
 * reading old log entries — see DocumentMovementTimeline::ACTION_LABELS —
 * that's a separate lookup table, unaffected by dropping these columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_assignments', function (Blueprint $table) {
            $table->dropColumn(['needs_approver', 'needs_approver_at']);
        });
    }

    public function down(): void
    {
        Schema::table('document_assignments', function (Blueprint $table) {
            $table->boolean('needs_approver')->default(false)->after('escalation_reason');
            $table->timestamp('needs_approver_at')->nullable()->after('needs_approver');
        });
    }
};
