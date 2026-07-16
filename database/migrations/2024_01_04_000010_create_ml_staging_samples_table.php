<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shared, cross-admin staging area for ML training samples — replaces
     * the earlier session-based approach, which silently lost progress on
     * logout, session expiry, or switching browsers/devices, and couldn't
     * be shared between different admin accounts building up the same
     * category's samples over time.
     */
    public function up(): void
    {
        Schema::create('ml_staging_samples', function (Blueprint $table) {
            $table->id();
            $table->string('category');
            $table->string('original_filename');
            $table->longText('extracted_text');
            $table->foreignId('staged_by')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_staging_samples');
    }
};
