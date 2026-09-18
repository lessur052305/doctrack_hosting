<?php

namespace App\Console\Commands;

use App\Models\NotificationRecord;
use App\Models\User;
use App\Services\ClassificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Run via: php artisan ml:auto-train-classifier
 * Scheduled every config('ml.auto_train_check_interval_minutes') (5)
 * minutes in bootstrap/app.php.
 *
 * The check itself is cheap (a handful of count queries) so running it
 * often costs nothing — see ClassificationService::autoTrainIfDue() for
 * the actual trigger conditions and the (expensive) retrain it only runs
 * once one of them is met. Fully automatic, by design — there is
 * deliberately no admin "train now" button once a model has been
 * bootstrapped (see AdminController::trainModel(), the one-time manual
 * step this replaces for every retrain after the first).
 */
class AutoTrainClassifier extends Command
{
    protected $signature = 'ml:auto-train-classifier';
    protected $description = 'Checks whether the classifier is due for an automatic retrain (batch size or age trigger) and runs it if so.';

    public function handle(ClassificationService $classifier): int
    {
        $result = $classifier->autoTrainIfDue();

        if ($result === null) {
            $this->info('Auto-train check: nothing due.');

            return self::SUCCESS;
        }

        $outcome = $result['kept']
            ? "kept — accuracy {$result['previousAccuracy']}% -> {$result['newAccuracy']}%"
            : "rolled back — new attempt scored {$result['newAccuracy']}%, below the active model's {$result['previousAccuracy']}%, so the active model was left unchanged";

        $message = "Automatic classifier retrain ({$result['version']}, {$result['documentsUsed']} real document(s) folded in): {$outcome}.";

        $this->info($message);
        Log::info($message);

        foreach (User::where('role', 'admin')->where('is_active', true)->get() as $admin) {
            NotificationRecord::send($admin->user_id, null, $message);
        }

        return self::SUCCESS;
    }
}
