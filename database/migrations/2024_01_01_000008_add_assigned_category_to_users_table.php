<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff category-assignment mechanism (Archive feature).
 * Each Staff (Originator/Approver) account can be assigned exactly one
 * document category. Their Archive view is then hard-filtered to only that
 * category's approved documents. Admin accounts are never restricted by
 * this column, regardless of its value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('assigned_category', 50)->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('assigned_category');
        });
    }
};
