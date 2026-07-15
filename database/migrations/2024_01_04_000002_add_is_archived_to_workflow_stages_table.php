<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 2: Dynamic Workflow Orchestration. document_assignments.stage_id
 * has no cascade/null-on-delete (DB-level RESTRICT), so a stage that has
 * ever had an assignment can never be hard-deleted — it can only be
 * archived. Archived stages are excluded from routing but remain visible
 * for historical reporting/audit purposes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_stages', function (Blueprint $table) {
            $table->boolean('is_archived')->default(false)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_stages', function (Blueprint $table) {
            $table->dropColumn('is_archived');
        });
    }
};
