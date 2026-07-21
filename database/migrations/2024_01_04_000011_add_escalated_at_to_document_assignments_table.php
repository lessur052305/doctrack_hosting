<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records WHEN an assignment was escalated to Admin, so the Admin grace
 * window (SlaService::autoApproveOne(), config('sla.admin_grace_hours'))
 * can be displayed as a countdown and used to schedule the event-driven
 * AutoApproveAssignmentJob. Previously only the boolean escalated_to_admin
 * existed, which can't answer "how much time is left".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_assignments', function (Blueprint $table) {
            $table->timestamp('escalated_at')->nullable()->after('escalated_to_admin');
        });
    }

    public function down(): void
    {
        Schema::table('document_assignments', function (Blueprint $table) {
            $table->dropColumn('escalated_at');
        });
    }
};
