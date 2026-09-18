<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_model_repository', function (Blueprint $table) {
            // How many cross-validation rounds produced accuracy_score —
            // shown on the ML Training page so the "how is this measured"
            // explanation states a real number instead of an assumed one
            // (see ClassificationService::estimateAccuracyViaCrossValidation,
            // which picks between 2-5 folds depending on the smallest
            // category's sample count).
            $table->unsignedTinyInteger('cv_folds')->nullable()->after('accuracy_score');
        });
    }

    public function down(): void
    {
        Schema::table('ml_model_repository', function (Blueprint $table) {
            $table->dropColumn('cv_folds');
        });
    }
};
