<?php

use App\Models\SlaHoliday;
use App\Models\SlaSetting;
use App\Services\BusinessHoursService;
use Carbon\Carbon;

// Mon–Sat 9:00–17:00, Sunday off — matches this app's default configuration.
beforeEach(function () {
    SlaSetting::current()->update([
        'work_start_time' => '09:00:00',
        'work_end_time' => '17:00:00',
        'working_days' => [1, 2, 3, 4, 5, 6],
    ]);
});

test('a working day within the configured days is recognized', function () {
    $service = app(BusinessHoursService::class);

    expect($service->isWorkingDay(Carbon::parse('2026-07-16'))) // Thursday
        ->toBeTrue();
});

test('sunday is not a working day by default', function () {
    $service = app(BusinessHoursService::class);

    expect($service->isWorkingDay(Carbon::parse('2026-07-19'))) // Sunday
        ->toBeFalse();
});

test('an admin-marked holiday overrides an otherwise-working weekday', function () {
    SlaHoliday::create(['holiday_date' => '2026-07-17']); // a Friday

    $service = app(BusinessHoursService::class);

    expect($service->isWorkingDay(Carbon::parse('2026-07-17')))->toBeFalse();
});

test('addBusinessMinutes stays within the same day when there is enough room', function () {
    $service = app(BusinessHoursService::class);

    // Thursday 9:00 AM + 60 minutes -> 10:00 AM same day.
    $result = $service->addBusinessMinutes(Carbon::parse('2026-07-16 09:00:00'), 60);

    expect($result->toDateTimeString())->toBe('2026-07-16 10:00:00');
});

test('addBusinessMinutes rolls over to the next working day when it runs out of room today', function () {
    $service = app(BusinessHoursService::class);

    // Thursday 3:30 PM (90 min left before 5 PM close) + 360 minutes.
    // 90 min used today -> 270 min remaining -> rolls to Friday 9:00 AM + 270min = 1:30 PM.
    $result = $service->addBusinessMinutes(Carbon::parse('2026-07-16 15:30:00'), 360);

    expect($result->toDateTimeString())->toBe('2026-07-17 13:30:00');
});

test('addBusinessMinutes skips a newly-marked holiday', function () {
    SlaHoliday::create(['holiday_date' => '2026-07-17']); // Friday off

    $service = app(BusinessHoursService::class);

    // Same scenario as above, but Friday is now a holiday -> rolls to Saturday instead.
    $result = $service->addBusinessMinutes(Carbon::parse('2026-07-16 15:30:00'), 360);

    expect($result->toDateTimeString())->toBe('2026-07-18 13:30:00');
});

test('addBusinessMinutes snaps a moment outside working hours forward before consuming minutes', function () {
    $service = app(BusinessHoursService::class);

    // Thursday 12:11 AM (before 9 AM open) + 15 minutes -> 9:15 AM same day.
    $result = $service->addBusinessMinutes(Carbon::parse('2026-07-16 00:11:00'), 15);

    expect($result->toDateTimeString())->toBe('2026-07-16 09:15:00');
});

test('nextWorkingDueDate leaves a due date on a working day untouched', function () {
    $service = app(BusinessHoursService::class);

    $result = $service->nextWorkingDueDate(Carbon::parse('2026-07-16 18:00:00')); // Thursday, working day

    expect($result->toDateTimeString())->toBe('2026-07-16 18:00:00');
});

test('nextWorkingDueDate shifts a due date off a non-working day, keeping the same time', function () {
    $service = app(BusinessHoursService::class);

    $result = $service->nextWorkingDueDate(Carbon::parse('2026-07-19 09:00:00')); // Sunday

    expect($result->toDateTimeString())->toBe('2026-07-20 09:00:00'); // Monday, same time
});

test('nextWorkingDueDate skips a holiday even if the underlying weekday is normally a working day', function () {
    SlaHoliday::create(['holiday_date' => '2026-07-17']); // Friday off

    $service = app(BusinessHoursService::class);

    $result = $service->nextWorkingDueDate(Carbon::parse('2026-07-17 15:00:00'));

    expect($result->toDateTimeString())->toBe('2026-07-18 15:00:00'); // Saturday, same time
});

test('isWithinWorkingWindow is true during open hours and false outside them', function () {
    $service = app(BusinessHoursService::class);

    expect($service->isWithinWorkingWindow(Carbon::parse('2026-07-16 12:00:00')))->toBeTrue()
        ->and($service->isWithinWorkingWindow(Carbon::parse('2026-07-16 20:00:00')))->toBeFalse()
        ->and($service->isWithinWorkingWindow(Carbon::parse('2026-07-19 12:00:00')))->toBeFalse(); // Sunday
});
