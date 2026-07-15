<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SlaSetting
 * ----------
 * Section 1: Operational Window Controls. Application-enforced singleton
 * (no DB constraint) holding the admin-configurable daily working window
 * and which weekdays count as working days. Read by BusinessHoursService.
 */
class SlaSetting extends Model
{
    protected $table = 'sla_settings';

    protected $fillable = [
        'work_start_time', 'work_end_time', 'working_days', 'updated_by',
    ];

    protected $casts = [
        'working_days' => 'array',
    ];

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by', 'user_id');
    }

    /** Self-healing singleton: creates the row with config-driven defaults on first access. */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'work_start_time' => config('sla.default_work_start'),
            'work_end_time' => config('sla.default_work_end'),
            'working_days' => config('sla.default_working_days'),
        ]);
    }

    public function isWorkingDay(int $carbonDayOfWeek): bool
    {
        return in_array($carbonDayOfWeek, $this->working_days ?? [], true);
    }
}
