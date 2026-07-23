<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_staging_samples', function (Blueprint $table) {
            // Null = never included in a training run yet. Set the moment
            // AdminController::trainModel() succeeds, to every sample
            // staged at that point — every row gets swept into every
            // training run today, so this is how the UI tells "already
            // taught the active model something" apart from "still
            // waiting for the next retrain," without ever deleting a
            // sample (see the lifetime-cap removal in the same change:
            // the corpus is meant to keep growing forever).
            $table->foreignId('trained_in_model_id')->nullable()
                ->after('staged_by')
                ->constrained('ml_model_repository', 'model_id')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ml_staging_samples', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trained_in_model_id');
        });
    }
};
