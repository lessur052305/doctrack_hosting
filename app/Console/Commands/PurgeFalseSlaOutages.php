<?php

namespace App\Console\Commands;

use App\Models\SlaOutageWindow;
use App\Services\SlaService;
use Illuminate\Console\Command;

/**
 * Run via: php artisan sla:purge-false-outages
 *
 * Before the outage detector's threshold was raised (SlaService::
 * OUTAGE_DETECTION_FLOOR_MINUTES, now 10), a perfectly healthy 5-minute
 * scheduler tick was recorded as an "outage" — one fake record per tick,
 * each of which also pushed pending deadlines forward. This lists every
 * recorded window shorter than the current floor (which the detector would
 * no longer create) and, on confirmation, deletes them. Real outages are
 * longer than the floor and are never touched.
 *
 * Deleting a record does NOT undo the deadline extension it already applied
 * (and the audit-log line it wrote is immutable) — this only cleans the
 * SLA Outage Windows table so reports stop counting downtime that never
 * happened. Deliberately manual: run it once after deploying, never
 * scheduled.
 */
class PurgeFalseSlaOutages extends Command
{
    protected $signature = 'sla:purge-false-outages {--force : Delete without asking for confirmation}';

    protected $description = 'Delete recorded SLA outage windows shorter than the current detection threshold (false positives from healthy scheduler ticks).';

    public function handle(): int
    {
        $floor = SlaService::OUTAGE_DETECTION_FLOOR_MINUTES;

        $false = SlaOutageWindow::whereNotNull('ended_at')->get()
            ->filter(fn (SlaOutageWindow $window) => abs($window->started_at->diffInMinutes($window->ended_at)) < $floor);

        $total = SlaOutageWindow::count();

        if ($false->isEmpty()) {
            $this->info("No false outage records — all {$total} recorded window(s) are {$floor} minutes or longer.");

            return self::SUCCESS;
        }

        $this->info("{$false->count()} of {$total} recorded outage window(s) are shorter than {$floor} minutes and would not be recorded today:");
        $this->table(
            ['Started', 'Ended', 'Minutes'],
            $false->take(10)->map(fn (SlaOutageWindow $w) => [
                $w->started_at->toDateTimeString(),
                $w->ended_at->toDateTimeString(),
                round(abs($w->started_at->diffInMinutes($w->ended_at)), 1),
            ])->all()
        );
        if ($false->count() > 10) {
            $this->line('… and '.($false->count() - 10).' more.');
        }

        if (! $this->option('force') && ! $this->confirm("Delete these {$false->count()} record(s)? Real outages are not affected.")) {
            $this->info('Nothing deleted.');

            return self::SUCCESS;
        }

        SlaOutageWindow::whereIn('id', $false->pluck('id'))->delete();
        $this->info("Deleted {$false->count()} false outage record(s); ".($total - $false->count()).' kept.');

        return self::SUCCESS;
    }
}
