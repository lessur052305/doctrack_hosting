<?php

use App\Models\SlaOutageWindow;
use Carbon\Carbon;

function outageWindow(string $started, int $minutes): SlaOutageWindow
{
    $start = Carbon::parse($started);

    return SlaOutageWindow::create([
        'started_at' => $start,
        'ended_at' => $start->copy()->addMinutes($minutes),
        'business_minutes_lost' => $minutes,
        'compensated_at' => $start->copy()->addMinutes($minutes),
    ]);
}

it('deletes windows shorter than the detection floor and keeps real outages', function () {
    $falseA = outageWindow('2026-09-25 10:00:00', 5);
    $falseB = outageWindow('2026-09-25 10:05:00', 5);
    $real = outageWindow('2026-09-25 13:00:00', 40);
    $overnight = outageWindow('2026-09-24 17:00:00', 705);

    $this->artisan('sla:purge-false-outages', ['--force' => true])
        ->expectsOutputToContain('2 of 4 recorded outage window(s) are shorter than 10 minutes')
        ->expectsOutputToContain('Deleted 2 false outage record(s); 2 kept.')
        ->assertExitCode(0);

    expect(SlaOutageWindow::pluck('id')->sort()->values()->all())->toBe(collect([$real->id, $overnight->id])->sort()->values()->all());
});

it('deletes nothing when the user declines the confirmation', function () {
    outageWindow('2026-09-25 10:00:00', 5);

    $this->artisan('sla:purge-false-outages')
        ->expectsConfirmation('Delete these 1 record(s)? Real outages are not affected.', 'no')
        ->expectsOutputToContain('Nothing deleted.')
        ->assertExitCode(0);

    expect(SlaOutageWindow::count())->toBe(1);
});

it('deletes after the user confirms', function () {
    outageWindow('2026-09-25 10:00:00', 5);

    $this->artisan('sla:purge-false-outages')
        ->expectsConfirmation('Delete these 1 record(s)? Real outages are not affected.', 'yes')
        ->assertExitCode(0);

    expect(SlaOutageWindow::count())->toBe(0);
});

it('reports cleanly when there is nothing to purge', function () {
    outageWindow('2026-09-25 13:00:00', 40);

    $this->artisan('sla:purge-false-outages')
        ->expectsOutputToContain('No false outage records')
        ->assertExitCode(0);

    expect(SlaOutageWindow::count())->toBe(1);
});
