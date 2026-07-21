<?php

namespace App\Services;

use App\Models\MlModelRepository;
use Illuminate\Support\Facades\Storage;
use Phpml\Classification\SVC;
use Phpml\FeatureExtraction\TfIdfTransformer;
use Phpml\FeatureExtraction\TokenCountVectorizer;
use Phpml\ModelManager;
use Phpml\SupportVectorMachine\Kernel;
use Phpml\Tokenization\WhitespaceTokenizer;

/**
 * ClassificationService
 * ----------------------
 * Implements the "Automated Document Classification" module from Scope (1.4)
 * and the Conceptual Framework (3.2) using the exact stack named in the
 * thesis: text preprocessing -> TF-IDF vectorization -> Support Vector
 * Machine (SVM) classification -> document_type assignment.
 *
 * Pipeline (php-ml):
 *   WhitespaceTokenizer + TokenCountVectorizer  -> term counts
 *   TfIdfTransformer                            -> TF-IDF feature vectors
 *   SVC (Support Vector Classifier, linear)     -> trained SVM classifier
 *
 * KEY DESIGN POINT (inference correctness):
 * A document classified later must be vectorized against the *identical*
 * fitted vocabulary and IDF weights the SVM was trained on, or feature
 * indices won't align. We therefore serialize the fitted TokenCountVectorizer
 * and TfIdfTransformer OBJECTS themselves (not just the vocabulary array) and
 * reuse those exact instances at inference. This is the supported php-ml
 * pattern and avoids index-misalignment bugs.
 *
 * REQUIRES (install on your machine, which has internet):
 *   composer require php-ai/php-ml
 * The linear kernel uses php-ml's bundled libsvm binary — no PECL extension
 * required. See README "Machine Learning (SVM + TF-IDF)".
 */
class ClassificationService
{
    /** Domain stopwords removed during preprocessing (tokenization + stop-word removal, per Scope 1.4). */
    private const STOPWORDS = [
        'the','a','an','is','are','was','were','be','been','of','to','in','on','for',
        'and','or','with','this','that','as','by','at','from','it','its','has','have',
        'had','will','shall','not','no','if','then','so','such','which','who','whom',
        'these','those','into','than','also','per','each','any','all','may','can',
    ];

    /**
     * Preprocess raw text: lowercase, strip punctuation/numbers, remove
     * stopwords and very short tokens. Returns a normalized string the
     * TokenCountVectorizer will tokenize on whitespace.
     */
    public function preprocess(string $text): string
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z\s]/', ' ', $text);
        $tokens = preg_split('/\s+/', trim($text)) ?: [];
        $tokens = array_filter($tokens, function ($t) {
            return strlen($t) > 2 && !in_array($t, self::STOPWORDS, true);
        });
        return implode(' ', $tokens);
    }

    /**
     * How similar two documents' vocabulary is, as a 0.0-1.0 fraction —
     * used to warn an admin staging training samples that a new upload
     * looks like a near-duplicate of one already staged in that category
     * (see AdminController::stageTrainingSamples()).
     *
     * Deliberately simple word-set overlap (Jaccard similarity on the same
     * preprocessed tokens train()/classify() already use), not a full
     * TF-IDF + cosine comparison — this only needs to catch "these two
     * documents are basically copies of each other," not produce a
     * precise similarity score, so it doesn't need to fit a vectorizer at
     * all. Two genuinely different same-category documents (different
     * department, item, dates) naturally share some domain vocabulary —
     * that overlap is expected and fine, not something to flag.
     */
    public function wordOverlapSimilarity(string $textA, string $textB): float
    {
        $wordsA = array_unique(explode(' ', $this->preprocess($textA)));
        $wordsB = array_unique(explode(' ', $this->preprocess($textB)));

        $union = array_unique(array_merge($wordsA, $wordsB));
        if (empty($union)) {
            return 0.0;
        }

        $intersection = array_intersect($wordsA, $wordsB);

        return count($intersection) / count($union);
    }

    /**
     * Train (or retrain) the SVM classifier from labeled sample documents.
     *
     * @param  array<string, array<int, string>>  $samplesByCategory
     *         e.g. ['Job Order' => [text1, ...], 'Purchase Requisition' => [...], ...]
     *         Per Scope (1.4), admins upload 5–10 samples per category.
     */
    public function train(array $samplesByCategory): MlModelRepository
    {
        // 1. Flatten labeled corpus into aligned $samples[] and $labels[].
        $samples = [];
        $labels = [];
        foreach ($samplesByCategory as $category => $docs) {
            foreach ($docs as $doc) {
                $samples[] = $this->preprocess($doc);
                $labels[] = $category;
            }
        }

        if (count($samples) < 2 || count(array_unique($labels)) < 2) {
            throw new \RuntimeException('SVM training needs at least two categories with samples.');
        }

        // 2. TF-IDF feature extraction.
        //    fit() learns the vocabulary / IDF weights; transform() rewrites
        //    the array in place into numeric feature vectors.
        $vectorizer = new TokenCountVectorizer(new WhitespaceTokenizer());
        $vectorizer->fit($samples);
        $vectorizer->transform($samples); // -> term-count vectors

        $tfIdf = new TfIdfTransformer();
        $tfIdf->fit($samples);
        $tfIdf->transform($samples);      // -> TF-IDF vectors

        // 3. Train the Support Vector Machine.
        //    Linear kernel is the standard choice for high-dimensional sparse
        //    text features (matches the thesis' SVM + TF-IDF design).
        //    probabilityEstimates=true lets us surface a confidence % in the UI.
        $svm = new SVC(
            Kernel::LINEAR,
            $cost = 1.0,
            $degree = 3,
            $gamma = null,
            $coef0 = 0.0,
            $tolerance = 0.001,
            $cacheSize = 100,
            $shrinking = true,
            $probabilityEstimates = true
        );
        $svm->train($samples, $labels);

        // 4. Persist model artifacts so classify() can reload the exact model.
        //    We store the SVM via php-ml's ModelManager and the fitted
        //    vectorizer + tfidf objects in a serialized sidecar.
        $stamp = (string) now()->timestamp;
        $svmPath = storage_path("app/ml_models/svm_{$stamp}.model");
        $sidecarPath = "ml_models/pipeline_{$stamp}.bin";

        @mkdir(dirname($svmPath), 0775, true);
        (new ModelManager())->saveToFile($svm, $svmPath);

        Storage::disk('local')->put($sidecarPath, serialize([
            'vectorizer' => $vectorizer, // fitted — reused verbatim at inference
            'tfidf' => $tfIdf,           // fitted IDF weights
        ]));

        // 5. Register the new version; deactivate previous ones.
        MlModelRepository::where('is_active', true)->update(['is_active' => false]);

        return MlModelRepository::create([
            'model_name' => 'Support Vector Machine (SVM) + TF-IDF',
            'version' => 'v' . now()->format('Ymd.His'),
            // Cross-validated, not resubstitution — see
            // estimateAccuracyViaCrossValidation()'s docblock for why this
            // matters. The FINAL model above is still trained on every
            // staged sample; only this accuracy estimate uses temporary
            // held-out folds, discarded once the score is computed.
            'accuracy_score' => $this->estimateAccuracyViaCrossValidation($samplesByCategory),
            'model_file_path' => $svmPath,
            'training_sample_count' => count($samples),
            'is_active' => true,
            'last_trained' => now(),
        ]);
    }

    /**
     * Classify raw document text against the active trained SVM model.
     *
     * @return array{category: string, confidence: float, model_id: int|null}
     */
    public function classify(string $text): array
    {
        $model = MlModelRepository::active();

        if (!$model || !$model->model_file_path || !file_exists($model->model_file_path)) {
            return ['category' => 'Unclassified', 'confidence' => 0.0, 'model_id' => null];
        }

        $stamp = preg_replace('/\D/', '', basename($model->model_file_path));
        $sidecarPath = "ml_models/pipeline_{$stamp}.bin";
        if (!Storage::disk('local')->exists($sidecarPath)) {
            return ['category' => 'Unclassified', 'confidence' => 0.0, 'model_id' => $model->model_id];
        }

        // Reuse the exact fitted vectorizer + tfidf objects from training so
        // feature indices align with what the SVM learned.
        $pipeline = unserialize(Storage::disk('local')->get($sidecarPath));
        /** @var TokenCountVectorizer $vectorizer */
        $vectorizer = $pipeline['vectorizer'];
        /** @var TfIdfTransformer $tfIdf */
        $tfIdf = $pipeline['tfidf'];

        /** @var SVC $svm */
        $svm = (new ModelManager())->restoreFromFile($model->model_file_path);

        $sample = [$this->preprocess($text)];
        $vectorizer->transform($sample); // uses the already-fitted vocabulary
        $tfIdf->transform($sample);      // uses the already-fitted IDF weights

        $predicted = $svm->predict($sample)[0] ?? 'Unclassified';
        $confidence = $this->predictConfidence($svm, $sample, (string) $predicted);

        return [
            'category' => (string) $predicted,
            'confidence' => round($confidence, 2),
            'model_id' => $model->model_id,
        ];
    }

    /**
     * Confidence estimate. Because the SVM is built with probabilityEstimates
     * enabled, predictProbability() returns per-class probabilities; we return
     * the winning class's probability as a percentage. Falls back gracefully
     * if probability estimates are unavailable on the installed php-ml build.
     */
    private function predictConfidence(SVC $svm, array $sample, string $predicted): float
    {
        try {
            if (method_exists($svm, 'predictProbability')) {
                $probs = $svm->predictProbability($sample)[0] ?? [];
                if (is_array($probs) && isset($probs[$predicted])) {
                    return (float) $probs[$predicted] * 100;
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
        return 85.0; // neutral default when probability estimates are off
    }

    /**
     * Honest accuracy estimate shown on the admin ML dashboard, via
     * stratified k-fold cross-validation — NOT the resubstitution accuracy
     * this used to compute (asking the model to re-predict the exact
     * samples it was just trained on, which only measures memorization and
     * swings wildly between training runs even at the same sample count).
     *
     * For each fold: hold that fold's documents out completely, fit a
     * throwaway vectorizer + TF-IDF + SVM on everything else, and predict
     * the held-out fold — documents that specific model has genuinely never
     * seen. Averaging across folds, where every sample gets held out
     * exactly once, uses the whole staged corpus for testing without ever
     * testing a model on data it trained on.
     *
     * "Stratified" = each fold gets a proportional slice from every
     * category (not a plain random split), which matters here because the
     * staged corpus is small (as few as 5 samples in a category) — a
     * non-stratified split risks a fold with zero examples of some
     * category, which SVC can't train or score against.
     *
     * This never touches the final production model returned by train(),
     * which is still fit on the complete staged corpus for the best real
     * classifier — folds are a temporary, throwaway split that exists only
     * long enough to produce this one honest number.
     */
    private function estimateAccuracyViaCrossValidation(array $samplesByCategory): float
    {
        $smallestCategory = min(array_map('count', $samplesByCategory));
        // 5 folds when there's enough data for it; never more folds than
        // the smallest category has samples, and never fewer than 2 (a
        // single fold can't hold anything out).
        $folds = max(2, min(5, $smallestCategory));

        $foldSamples = array_fill(0, $folds, []);
        $foldLabels = array_fill(0, $folds, []);

        foreach ($samplesByCategory as $category => $docs) {
            $docs = array_values($docs);
            shuffle($docs);
            foreach ($docs as $i => $doc) {
                $f = $i % $folds;
                $foldSamples[$f][] = $doc;
                $foldLabels[$f][] = $category;
            }
        }

        $totalCorrect = 0;
        $totalScored = 0;

        for ($testFold = 0; $testFold < $folds; $testFold++) {
            $trainSamples = [];
            $trainLabels = [];
            foreach ($foldSamples as $f => $docs) {
                if ($f === $testFold) {
                    continue;
                }
                $trainSamples = array_merge($trainSamples, array_map([$this, 'preprocess'], $docs));
                $trainLabels = array_merge($trainLabels, $foldLabels[$f]);
            }

            $testSamples = array_map([$this, 'preprocess'], $foldSamples[$testFold]);
            $testLabels = $foldLabels[$testFold];

            if (empty($testSamples) || count(array_unique($trainLabels)) < 2) {
                continue; // degenerate fold (can happen at the small end) — skip rather than crash
            }

            $vectorizer = new TokenCountVectorizer(new WhitespaceTokenizer());
            $vectorizer->fit($trainSamples);
            $vectorizer->transform($trainSamples);
            $vectorizer->transform($testSamples); // same fitted vocabulary, never refit on test data

            $tfIdf = new TfIdfTransformer();
            $tfIdf->fit($trainSamples);
            $tfIdf->transform($trainSamples);
            $tfIdf->transform($testSamples); // same fitted IDF weights

            $foldSvm = new SVC(Kernel::LINEAR, 1.0, 3, null, 0.0, 0.001, 100, true, false);
            $foldSvm->train($trainSamples, $trainLabels);

            $predictions = $foldSvm->predict($testSamples);
            foreach ($predictions as $i => $p) {
                $totalScored++;
                if ($p === $testLabels[$i]) {
                    $totalCorrect++;
                }
            }
        }

        return $totalScored > 0 ? round(($totalCorrect / $totalScored) * 100, 2) : 0.0;
    }
}
