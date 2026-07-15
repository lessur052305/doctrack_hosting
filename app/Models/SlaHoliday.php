<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SlaHoliday
 * ----------
 * Section 1: Holiday & Closure Management. Admin-toggled non-working
 * dates (Calendar View), treated by BusinessHoursService as frozen time,
 * identical to a non-working weekday.
 */
class SlaHoliday extends Model
{
    protected $table = 'sla_holidays';

    protected $fillable = [
        'holiday_date', 'label', 'created_by',
    ];

    protected $casts = [
        'holiday_date' => 'date',
    ];

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }
}
