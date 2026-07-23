<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_repository', function (Blueprint $table) {
            // Which model was active the moment this document was
            // confirmed out of the ML review queue (null if none existed
            // yet) — lets "Re-check" only appear once the ACTIVE model has
            // genuinely changed since then, instead of being clickable any
            // time and producing a meaningless no-op result (re-classifying
            // against the exact same model it was already confirmed
            // under). See AdminController::confirmReviewedDocument()/
            // recheckFlaggedDocument().
            $table->foreignId('confirmed_at_model_id')->nullable()
                ->after('ml_recheck_dismissed_at')
                ->constrained('ml_model_repository', 'model_id')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_repository', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_at_model_id');
        });
    }
};
