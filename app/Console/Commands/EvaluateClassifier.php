<?php

namespace App\Console\Commands;

use App\Models\MlStagingSample;
use App\Services\ClassificationService;
use App\Services\ValidationService;
use Illuminate\Console\Command;

/**
 * Run via: php artisan ml:evaluate-classifier
 *
 * Reports the classifier's Accuracy, Precision, Recall, and F1-Score (per
 * category and macro-averaged), plus its confusion matrix — the real
 * numbers for the capstone's "Machine Learning Model Evaluation" table
 * (Table 32), which previously had no way to be filled in honestly since
 * nothing in the codebase computed anything beyond a single accuracy
 * figure. See ClassificationService::evaluateClassifier() for the
 * fixed-seed cross-validation this runs — re-running this command
 * reproduces the same numbers every time, unlike the accuracy estimate
 * AutoTrainClassifier uses internally, which is deliberately unpinned.
 *
 * Reporting-only: never writes to ml_model_repository or activates a model.
 */
class EvaluateClassifier extends Command
{
    protected $signature = 'ml:evaluate-classifier {--seed=42 : Fixed random seed for reproducible cross-validation folds}';

    protected $description = 'Report the classifier\'s Accuracy, Precision, Recall, F1-Score, and confusion matrix via fixed-seed cross-validation';

    public function handle(ClassificationService $classifier): int
    {
        $categories = ValidationService::knownCategories();
        $samplesByCategory = [];

        foreach ($categories as $category) {
            $samplesByCategory[$category] = MlStagingSample::curatedTextsFor($category)->all();
        }

        $counts = collect($samplesByCategory)->map(fn ($docs) => count($docs));
        $this->info('Staged training sample counts: '.$counts->map(fn ($c, $cat) => "{$cat}={$c}")->implode(', '));

        if ($counts->min() < 2) {
            $this->error('At least 2 staged samples per category are needed to evaluate. Stage more samples first (Admin > ML Training).');

            return self::FAILURE;
        }

        $seed = (int) $this->option('seed');
        $result = $classifier->evaluateClassifier($samplesByCategory, $seed);

        $this->newLine();
        $this->info("Folds: {$result['folds']}  |  Test documents scored: {$result['total']}  |  Seed: {$seed}");
        $this->info("Overall Accuracy: {$result['accuracy']}%");

        $this->newLine();
        $this->table(
            ['Category', 'Precision', 'Recall', 'F1-Score', 'Support'],
            collect($result['perCategory'])->map(fn ($m, $cat) => [
                $cat, "{$m['precision']}%", "{$m['recall']}%", "{$m['f1']}%", $m['support'],
            ])->values()->all()
        );
        $this->table(
            ['Macro Average (unweighted mean across categories)', 'Precision', 'Recall', 'F1-Score'],
            [['', "{$result['macro']['precision']}%", "{$result['macro']['recall']}%", "{$result['macro']['f1']}%"]]
        );

        $this->newLine();
        $this->info('Confusion Matrix (rows = actual, columns = predicted):');
        $matrixCategories = array_keys($result['confusionMatrix']);
        $header = array_merge(['Actual \\ Predicted'], $matrixCategories);
        $rows = [];
        foreach ($matrixCategories as $actual) {
            $row = [$actual];
            foreach ($matrixCategories as $predicted) {
                $row[] = $result['confusionMatrix'][$actual][$predicted] ?? 0;
            }
            $rows[] = $row;
        }
        $this->table($header, $rows);

        return self::SUCCESS;
    }
}
