<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the manual "mark yourself busy" self-toggle entirely — it was
 * never reachable from any UI (confirmed: no view ever linked to
 * ApprovalController::toggleAvailability(), now also removed), real stage
 * routing stopped consulting it long ago, and a self-reported flag is easy
 * to leave stale/misused anyway. Superseded by an automatic online-based
 * "Available" status (see the last_seen_at migration and User::isOnline()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_busy');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_busy')->default(false)->after('assigned_category');
        });
    }
};
