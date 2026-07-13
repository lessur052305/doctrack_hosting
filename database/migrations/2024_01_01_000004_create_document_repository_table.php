<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DOCUMENT_REPOSITORY — Data Dictionary Table 3.5.2
 * Core data hub: physical file metadata, OCR/extracted text, ML
 * classification result, validation & global lifecycle status.
 *
 * global_status implements the state machine from Section 5:
 * processing -> classified_validated -> approved | rejected
 * (auto_approved is a sub-state of approved reached via SLA fallback).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_repository', function (Blueprint $table) {
            $table->id('document_id');
            $table->foreignId('originator_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->foreignId('model_id')->nullable()->constrained('ml_model_repository', 'model_id')->nullOnDelete();

            $table->string('title', 255);
            $table->string('file_path', 255);
            $table->string('original_filename', 255)->nullable();
            $table->string('mime_type', 100)->nullable();

            $table->longText('ocr_text')->nullable();
            $table->boolean('used_ocr_fallback')->default(false); // true if born-digital extraction failed

            $table->string('ml_category', 50)->nullable();
            $table->decimal('ml_confidence', 5, 2)->nullable();

            $table->boolean('is_validated')->default(false);
            $table->json('validation_errors')->nullable(); // list of missing required fields/sections

            $table->dateTime('due_date')->nullable();

            $table->enum('global_status', [
                'processing',
                'classified_validated',
                'approved',
                'auto_approved',
                'rejected',
            ])->default('processing');

            $table->timestamp('upload_date')->useCurrent();
            $table->timestamps();

            $table->index(['ml_category', 'global_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_repository');
    }
};