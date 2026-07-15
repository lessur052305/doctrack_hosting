<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SLA_HOLIDAYS — Section 1: Holiday & Closure Management. Admin-toggled
 * non-working dates (via the Calendar View), treated by BusinessHoursService
 * as frozen time, identical to a non-working weekday.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_holidays', function (Blueprint $table) {
            $table->id();
            $table->date('holiday_date')->unique();
            $table->string('label')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_holidays');
    }
};
