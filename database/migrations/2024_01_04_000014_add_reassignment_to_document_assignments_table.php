<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deactivation handoff (Feature): when an approver with pending work is
 * deactivated, their still-pending stages are automatically reassigned to
 * the same least-busy-eligible-approver logic normal routing already uses
 * (see WorkflowService::findReplacementApprover()/reassignAssignment()).
 * reassigned_from/at/reason record that this happened, so the pipeline can
 * show "Reassigned from X" instead of looking like a normal fresh
 * assignment.
 *
 * escalation_reason distinguishes a genuine SLA breach (null — the
 * existing, only path until now) from an assignment escalated immediately
 * because NO eligible replacement existed (see SlaService::
 * escalateForReassignmentFailure()) — deliberately NOT logged as an
 * SlaViolation, since the original approver didn't actually fail to act in
 * time; counting it as a breach would unfairly skew the Violations Report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_assignments', function (Blueprint $table) {
            $table->timestamp('reassigned_at')->nullable()->after('acted_at');
            $table->foreignId('reassigned_from')->nullable()->after('reassigned_at')
                ->constrained('users', 'user_id')->nullOnDelete();
            $table->text('reassignment_reason')->nullable()->after('reassigned_from');
            $table->string('escalation_reason')->nullable()->after('escalated_at');
        });
    }

    public function down(): void
    {
        Schema::table('document_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reassigned_from');
            $table->dropColumn(['reassigned_at', 'reassignment_reason', 'escalation_reason']);
        });
    }
};
