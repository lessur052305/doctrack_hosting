<?php

namespace App\Services;

use App\Models\SlaHoliday;
use App\Models\SlaSetting;
use Carbon\Carbon;

/**
 * BusinessHoursService
 * ---------------------
 * Section 1: Operational Window Controls & Holiday Management. Computes
 * business-hours-aware timestamps against the admin-configured daily
 * working window (SlaSetting) and non-working dates (SlaHoliday), which
 * are treated identically to a non-working weekday.
 *
 * Settings are loaded once per instance (constructor) — this service is
 * resolved fresh per request via normal DI, so there's no staleness risk
 * and deliberately no Cache:: usage (CACHE_STORE=database has no `cache`
 * table migrated in this app, same latent gap the queue tables had).
 *
 * The `due_date` an Originator supplies is NEVER shifted by this service —
 * it's the hard wall-clock ceiling and is only ever used elsewhere as a
 * post-hoc clamp on top of whatever this service computes. This service
 * only governs how an SLA *duration* (e.g. "45 minutes") is distributed
 * forward from "now" across business time.
 *
 * DST note: config('app.timezone') is hardcoded to Asia/Manila, which
 * observes no DST, so this is a non-issue today. The algorithm is
 * DST-safe by construction regardless — it only compares wall-clock
 * date/time components via Carbon's timezone-aware methods, never raw
 * UTC-offset arithmetic.
 */
class BusinessHoursService
{
    /** Trip-wire against misconfiguration (e.g. empty working_days or holiday-flooded calendar), not a real limit. */
    private const MAX_ITERATIONS = 400;

    private array $workingDays;
    private string $workStartTime;
    private string $workEndTime;

    /** ['Y-m-d' => true, ...] for O(1) lookup. */
    private array $holidayDates;

    public function __construct()
    {
        $settings = SlaSetting::current();
        $this->workingDays = $settings->working_days ?: config('sla.default_working_days');
        $this->workStartTime = $settings->work_start_time;
        $this->workEndTime = $settings->work_end_time;

        $this->holidayDates = SlaHoliday::pluck('holiday_date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->flip()
            ->all();
    }

    public function isWorkingDay(Carbon $date): bool
    {
        return in_array($date->dayOfWeek, $this->workingDays, true)
            && !isset($this->holidayDates[$date->toDateString()]);
    }

    /**
     * True if $moment falls inside an actual working window (working day,
     * between start/end time) — i.e. the SLA clock is actively progressing
     * right now, as opposed to frozen overnight/weekend/holiday time.
     * Used purely for UI clarity (see components using this to explain why
     * a countdown looks longer than the underlying business-hours budget).
     */
    public function isWithinWorkingWindow(Carbon $moment): bool
    {
        return $this->isWorkingDay($moment)
            && $moment->gte($this->startOfWindow($moment))
            && $moment->lt($this->endOfWindow($moment));
    }

    /**
     * If $from already falls inside a working window, returns it
     * unchanged. Otherwise advances to the start of the next working
     * window (skipping non-working weekdays and holiday dates).
     */
    public function nextWorkingMoment(Carbon $from): Carbon
    {
        $cursor = $from->copy();

        for ($i = 0; $i <= self::MAX_ITERATIONS; $i++) {
            if (!$this->isWorkingDay($cursor)) {
                $cursor = $this->startOfWindow($cursor->copy()->addDay());
                continue;
            }

            $start = $this->startOfWindow($cursor);
            $end = $this->endOfWindow($cursor);

            if ($cursor->lt($start)) {
                return $start;
            }
            if ($cursor->gte($end)) {
                $cursor = $this->startOfWindow($cursor->copy()->addDay());
                continue;
            }

            return $cursor;
        }

        throw new \RuntimeException(
            'BusinessHoursService: no working day found within ' . self::MAX_ITERATIONS .
            ' days — check sla_settings.working_days / sla_holidays for misconfiguration.'
        );
    }

    /**
     * Adds $minutes of business time to $from, skipping evenings,
     * non-working weekdays, and holiday dates, returning the resulting
     * absolute deadline.
     */
    public function addBusinessMinutes(Carbon $from, int $minutes): Carbon
    {
        $cursor = $this->nextWorkingMoment($from);

        for ($i = 0; $i <= self::MAX_ITERATIONS; $i++) {
            $endOfToday = $this->endOfWindow($cursor);
            $availableToday = $cursor->diffInMinutes($endOfToday, false);

            if ($minutes <= $availableToday) {
                return $cursor->copy()->addMinutes($minutes);
            }

            $minutes -= $availableToday;
            $cursor = $this->nextWorkingMoment($this->startOfWindow($cursor->copy()->addDay()));
        }

        throw new \RuntimeException(
            'BusinessHoursService: could not resolve a deadline within ' . self::MAX_ITERATIONS .
            ' working-day iterations — check sla_settings.working_days / sla_holidays for misconfiguration.'
        );
    }

    private function startOfWindow(Carbon $date): Carbon
    {
        return $date->copy()->setTimeFromTimeString($this->workStartTime);
    }

    private function endOfWindow(Carbon $date): Carbon
    {
        return $date->copy()->setTimeFromTimeString($this->workEndTime);
    }
}
