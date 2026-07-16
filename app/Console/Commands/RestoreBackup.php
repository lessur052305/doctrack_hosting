<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Run via: php artisan backup:restore {timestamp} [--force]
 * Never scheduled — this is a manual, emergency-recovery-only command.
 * {timestamp} matches the suffix backup:run produced, e.g.
 * "2026-07-16_143000" for db_2026-07-16_143000.sql.gz.
 *
 * Destructive: overwrites the live database and storage/app/{documents,
 * ml_models,ml_datasets} with the backup's contents. Requires --force
 * because there's no reasonable default here — a mistyped timestamp
 * pointed at production would be a real incident, not a rounding error.
 */
class RestoreBackup extends Command
{
    protected $signature = 'backup:restore {timestamp} {--force : Required — skips the confirmation prompt}';
    protected $description = 'Restore the database and file storage from a backup:run snapshot. Destructive.';

    public function handle(): int
    {
        $timestamp = $this->argument('timestamp');
        $backupDir = storage_path('app/backups');
        $dumpPath = "{$backupDir}/db_{$timestamp}.sql.gz";
        $archivePath = "{$backupDir}/files_{$timestamp}.tar.gz";

        if (!is_file($dumpPath) && !is_file($archivePath)) {
            $this->error("No backup found for timestamp '{$timestamp}' in {$backupDir}.");
            $this->line('Available timestamps: ' . implode(', ', $this->availableTimestamps($backupDir)));

            return self::FAILURE;
        }

        if (!$this->option('force')) {
            $this->warn('This will OVERWRITE the live database and document/ML-model storage.');
            if (!$this->confirm("Restore from '{$timestamp}'? This cannot be undone.")) {
                $this->info('Aborted.');

                return self::SUCCESS;
            }
        }

        $ok = true;
        if (is_file($dumpPath)) {
            $ok = $this->restoreDatabase($dumpPath) && $ok;
        } else {
            $this->warn("No database dump found at {$dumpPath} — skipping DB restore.");
        }

        if (is_file($archivePath)) {
            $ok = $this->restoreFiles($archivePath) && $ok;
        } else {
            $this->warn("No file archive found at {$archivePath} — skipping file restore.");
        }

        if ($ok) {
            $this->info("Restore complete from '{$timestamp}'.");

            return self::SUCCESS;
        }

        $this->error('Restore finished with errors — see output above.');

        return self::FAILURE;
    }

    private function restoreDatabase(string $dumpPath): bool
    {
        $defaultsFile = tempnam(sys_get_temp_dir(), 'my-cnf-');
        file_put_contents($defaultsFile, sprintf(
            "[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n",
            config('database.connections.mysql.username'),
            config('database.connections.mysql.password'),
            config('database.connections.mysql.host'),
            config('database.connections.mysql.port'),
        ));
        chmod($defaultsFile, 0600);

        try {
            $database = config('database.connections.mysql.database');
            $this->info('Restoring database...');

            $process = Process::fromShellCommandline(
                'gunzip -c ' . escapeshellarg($dumpPath)
                . ' | mysql --defaults-extra-file=' . escapeshellarg($defaultsFile)
                . ' ' . escapeshellarg($database)
            );
            $process->setTimeout(300);
            $process->run();

            if (!$process->isSuccessful()) {
                $this->error('Database restore failed: ' . $process->getErrorOutput());

                return false;
            }

            $this->info('Database restored.');

            return true;
        } finally {
            unlink($defaultsFile);
        }
    }

    private function restoreFiles(string $archivePath): bool
    {
        $this->info('Restoring files...');

        $process = new Process(['tar', '-xzf', $archivePath, '-C', storage_path('app')]);
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->error('File restore failed: ' . $process->getErrorOutput());

            return false;
        }

        $this->info('Files restored.');

        return true;
    }

    private function availableTimestamps(string $backupDir): array
    {
        $files = glob("{$backupDir}/db_*.sql.gz");
        $timestamps = array_map(
            fn ($f) => str_replace(['db_', '.sql.gz'], '', basename($f)),
            $files
        );
        sort($timestamps);

        return $timestamps ?: ['(none found)'];
    }
}
