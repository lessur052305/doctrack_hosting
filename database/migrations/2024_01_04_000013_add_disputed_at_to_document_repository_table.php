<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an Admin flag a document as disputed after reviewing a stage the
 * SYSTEM auto-approved (see AdminController::reviewAutoApproval()). This is
 * deliberately a separate column rather than a new global_status enum
 * value — global_status is a hard DB-level ENUM (see
 * 2024_01_01_000004_create_document_repository_table.php), and Doctrine
 * DBAL (required to alter an enum's value set via Schema::table()->change())
 * isn't installed in this project. Keeping global_status as-is also
 * preserves the historical fact that the document WAS auto-approved,
 * with the dispute layered on top rather than replacing it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_repository', function (Blueprint $table) {
            $table->timestamp('disputed_at')->nullable()->after('global_status');
        });
    }

    public function down(): void
    {
        Schema::table('document_repository', function (Blueprint $table) {
            $table->dropColumn('disputed_at');
        });
    }
};
