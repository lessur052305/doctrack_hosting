<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SLA_VIOLATIONS — Section 4: SLA Violation & Accountability. Logged the
 * moment an assignment's SLA window is detected as breached (see
 * CheckParallelSlas). stage_name is a denormalized snapshot rather than a
 * stage_id FK so historical violations stay meaningful even after a stage
 * is later renamed/archived/deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_violations', function (Blueprint $table) {
            $table->id('violation_id');
            $table->foreignId('document_id')->constrained('document_repository', 'document_id')->cascadeOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->dateTime('violation_timestamp');
            $table->unsignedInteger('duration_overdue'); // minutes overdue at moment of detection
            $table->string('stage_name');

            $table->index('approver_id');
            $table->index('violation_timestamp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_violations');
    }
};
