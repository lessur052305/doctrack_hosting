<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual availability flag an Approver can toggle on their own dashboard
 * ("busy/away"). Used by WorkflowService's load-balancing/fallback logic:
 * a busy approver is skipped in favor of an available peer on the same
 * stage, unless skipping them would leave nobody eligible at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_busy')->default(false)->after('assigned_category');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_busy');
        });
    }
};