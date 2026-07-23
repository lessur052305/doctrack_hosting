<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_repository', function (Blueprint $table) {
            // Pure UI flag for the "Confirmed From Review" panel on the ML
            // Training page — set when an admin dismisses a row they've
            // already re-checked and are done watching. Deliberately
            // doesn't touch ml_review_status (still means "confirmed and
            // routed," a real workflow state), the document itself, or its
            // training sample — only whether this one panel keeps showing it.
            $table->timestamp('ml_recheck_dismissed_at')->nullable()->after('ml_rechecked_at');
        });
    }

    public function down(): void
    {
        Schema::table('document_repository', function (Blueprint $table) {
            $table->dropColumn('ml_recheck_dismissed_at');
        });
    }
};
