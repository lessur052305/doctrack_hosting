<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SLA_SETTINGS — Section 1: Operational Window Controls. Singleton row
 * (enforced at the application layer via SlaSetting::current(), not a DB
 * constraint) holding the admin-configurable daily working window and
 * which weekdays count as working days. Read by BusinessHoursService to
 * compute business-hours-aware SLA deadlines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_settings', function (Blueprint $table) {
            $table->id();
            $table->time('work_start_time')->default('09:00:00');
            $table->time('work_end_time')->default('17:00:00');
            $table->json('working_days'); // Carbon dayOfWeek ints, 0=Sun..6=Sat; default [1,2,3,4,5,6] = Mon-Sat
            $table->foreignId('updated_by')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_settings');
    }
};
