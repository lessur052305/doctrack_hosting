<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ml_margin: how far ahead the winning category was over the
     * runner-up, at classification time (see ClassificationService::
     * predictConfidenceAndMargin()) — what the automatic tiering in
     * WorkflowService::ingest() uses alongside ml_confidence to decide
     * whether a document is trusted automatically or rejected as
     * ambiguous.
     *
     * used_for_training_at: set the moment a document gets folded into
     * an automatic classifier retrain (kept or rolled back either way) —
     * both how the system knows which confidently-classified documents
     * are still "waiting" to teach the model, and a guard against ever
     * counting or re-adding the same document twice.
     */
    public function up(): void
    {
        Schema::table('document_repository', function (Blueprint $table) {
            $table->float('ml_margin')->nullable()->after('ml_confidence');
            $table->timestamp('used_for_training_at')->nullable()->after('ml_margin');
        });
    }

    public function down(): void
    {
        Schema::table('document_repository', function (Blueprint $table) {
            $table->dropColumn(['ml_margin', 'used_for_training_at']);
        });
    }
};
