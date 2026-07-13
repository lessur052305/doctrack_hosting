<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ML_MODEL_REPOSITORY — Data Dictionary Table 3.5.5
 * Stores each trained classifier version so Document_Repository rows can
 * reference exactly which model produced their ml_category (audit-ability).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_model_repository', function (Blueprint $table) {
            $table->id('model_id');
            $table->string('model_name', 100); // e.g. "Support Vector Machine (SVM) + TF-IDF"
            $table->string('version', 30);
            $table->decimal('accuracy_score', 5, 2)->nullable();
            $table->string('model_file_path', 255)->nullable(); // serialized vocabulary/centroids
            $table->unsignedInteger('training_sample_count')->default(0); // 5–10 per category per scope
            $table->boolean('is_active')->default(false);
            $table->timestamp('last_trained')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_model_repository');
    }
};