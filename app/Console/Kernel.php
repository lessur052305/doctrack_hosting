<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // SLA daemon: sweeps document_assignments for expired due dates,
        // escalates to Admin, and auto-approves if the Admin grace window
        // also lapses (Section 5). Runs every 5 minutes.
        $schedule->command('sla:check')->everyFiveMinutes()->withoutOverlapping();
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
        require base_path('routes/console.php');
    }
}
