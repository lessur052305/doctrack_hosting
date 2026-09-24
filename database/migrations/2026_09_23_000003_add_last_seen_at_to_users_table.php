<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online-presence tracking (Feature: chat + "Available" status), a plain
 * heartbeat timestamp rather than Reverb presence channels — simpler, no
 * new real-time pattern for this app, and avoids the multi-tab/device
 * dedup complexity presence channels would introduce. "Online" = updated
 * within the last ~60-90 seconds (see User::isOnline()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_seen_at');
        });
    }
};
