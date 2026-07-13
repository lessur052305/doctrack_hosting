<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOTIFICATIONS — Data Dictionary Table 3.5.7
 * Communication queue for Process 6.0 (Notification Service). Includes a
 * priority flag so SLA-escalation alerts (Section 5) render distinctly from
 * routine "new task" alerts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id('notification_id');
            $table->foreignId('recipient_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('document_repository', 'document_id')->nullOnDelete();
            $table->text('message_body');
            $table->enum('priority', ['normal', 'high'])->default('normal');
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['recipient_id', 'is_read']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
