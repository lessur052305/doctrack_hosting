<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links each SlaViolation back to the specific DocumentAssignment it was
 * raised for. Without this, the violations report could only show the
 * document's current overall status, not the exact resolution timestamp
 * (or "still pending") of the specific stage that breached — see
 * SlaService::escalate(), which now populates this at creation time.
 * Nullable: existing violation rows predate this column and stay
 * unlinked; the report falls back to violation_timestamp for those.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sla_violations', function (Blueprint $table) {
            $table->foreignId('assignment_id')->nullable()->after('document_id')
                ->constrained('document_assignments', 'assignment_id')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sla_violations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assignment_id');
        });
    }
};
