<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * escalated_to_admin / escalated_at / escalation_reason belonged to the old
 * "escalate to the Admin, who then has a grace window to decide" flow. A missed
 * SLA deadline now auto-approves the seat immediately and the Admin reviews it
 * afterward (see SlaService::escalateApproverMiss()/autoApproveOne()), so
 * nothing has set escalated_to_admin true for a long time, and nothing reads
 * any of the three anymore — every remaining check on them was dead, and one
 * of them (the "already escalated" guard on a late decision) silently
 * protected nothing. Approver-side accountability lives in sla_violations;
 * the Admin's follow-up in admin_violations and the review_due_at /
 * admin_reviewed_at columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_assignments', function (Blueprint $table) {
            $table->dropColumn(['escalated_to_admin', 'escalated_at', 'escalation_reason']);
        });
    }

    public function down(): void
    {
        Schema::table('document_assignments', function (Blueprint $table) {
            $table->boolean('escalated_to_admin')->default(false);
            $table->timestamp('escalated_at')->nullable()->after('escalated_to_admin');
            $table->string('escalation_reason')->nullable()->after('escalated_at');
        });
    }
};
