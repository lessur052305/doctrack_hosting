<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AUDIT_LOGS — Data Dictionary Table 3.5.6
 * Immutable chronological record of every state transition, approval,
 * rejection, and manual/system override (Section 6 requirement).
 * Never updated or deleted by application code — insert-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id('log_id');
            $table->foreignId('user_id')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('document_repository', 'document_id')->nullOnDelete();
            $table->string('action_type', 50); // login, upload, classify, validate, approve, reject, admin_override, auto_approve
            $table->text('description')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('timestamp')->useCurrent();

            $table->index(['document_id', 'action_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
