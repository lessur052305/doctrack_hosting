<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 5 (extended): resubmission/versioning. A rejected document was
 * previously a dead end — the Originator's only option was to upload an
 * entirely new, unrelated document. previous_version_id links a
 * resubmission back to the document it revises, forming a version chain;
 * version_number is a simple 1-based display counter along that chain.
 * Self-referencing FK, nullOnDelete so deleting an old version (rare,
 * no UI path for it today) never cascades into deleting its revisions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_repository', function (Blueprint $table) {
            $table->foreignId('previous_version_id')->nullable()->after('document_id')
                ->constrained('document_repository', 'document_id')->nullOnDelete();
            $table->unsignedInteger('version_number')->default(1)->after('previous_version_id');
        });
    }

    public function down(): void
    {
        Schema::table('document_repository', function (Blueprint $table) {
            $table->dropConstrainedForeignId('previous_version_id');
            $table->dropColumn('version_number');
        });
    }
};
