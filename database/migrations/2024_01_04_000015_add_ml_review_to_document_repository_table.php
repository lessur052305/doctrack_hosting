<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_repository', function (Blueprint $table) {
            // 'pending'|'staged'|'dismissed' — plain string, not a DB ENUM
            // (see the disputed_at migration's note on why: ENUM value sets
            // don't alter portably across MySQL/SQLite without doctrine/dbal).
            // Null means "never flagged" (either it classified confidently,
            // or it predates this feature).
            $table->string('ml_review_status')->nullable()->after('ml_confidence');

            // Populated by the "Re-check" action after a document has been
            // staged into training and the model retrained — re-runs
            // classify() against the SAME stored text and records the
            // result here, deliberately separate from ml_category/
            // ml_confidence so the document's original classification
            // (which already drove its actual workflow routing) is never
            // silently overwritten.
            $table->string('ml_recheck_category')->nullable();
            $table->float('ml_recheck_confidence')->nullable();
            $table->timestamp('ml_rechecked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('document_repository', function (Blueprint $table) {
            $table->dropColumn(['ml_review_status', 'ml_recheck_category', 'ml_recheck_confidence', 'ml_rechecked_at']);
        });
    }
};
