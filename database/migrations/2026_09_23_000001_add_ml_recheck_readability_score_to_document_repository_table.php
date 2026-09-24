<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Companion to ml_recheck_category/ml_recheck_confidence — readability
 * previously had no recheck-after-training equivalent at all (its
 * vocabulary source never grew from routed documents, only from
 * admin-curated MlStagingSample rows — see ValidationService::
 * categoryVocabulary()'s widened source added alongside this column).
 * Reuses ml_rechecked_at as its own timestamp too, same batch/same run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_repository', function (Blueprint $table) {
            $table->unsignedTinyInteger('ml_recheck_readability_score')->nullable()->after('ml_rechecked_at');
        });
    }

    public function down(): void
    {
        Schema::table('document_repository', function (Blueprint $table) {
            $table->dropColumn('ml_recheck_readability_score');
        });
    }
};
