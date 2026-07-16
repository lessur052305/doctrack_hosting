<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_repository', function (Blueprint $table) {
            // Marks documents created via ArchiveController::storeLegacy()
            // — admin-injected straight to 'approved', bypassing
            // classification/validation/the approval workflow entirely.
            // Needed so the UI can visibly flag these everywhere they
            // appear; without it, an imported document is indistinguishable
            // from one that actually went through peer review.
            $table->boolean('is_legacy_import')->default(false)->after('global_status');
        });
    }

    public function down(): void
    {
        Schema::table('document_repository', function (Blueprint $table) {
            $table->dropColumn('is_legacy_import');
        });
    }
};
