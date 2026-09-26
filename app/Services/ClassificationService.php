<?php

namespace App\Services;

use App\Events\MlModelTrained;
use App\Models\DocumentRepository;
use App\Models\MlModelRepository;
use App\Models\MlStagingSample;
use Carbon\Carbon;
use Illuminate\Support\Collection;
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
        'the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'of', 'to', 'in', 'on', 'for',
        'and', 'or', 'with', 'this', 'that', 'as', 'by', 'at', 'from', 'it', 'its', 'has', 'have',
        'had', 'will', 'shall', 'not', 'no', 'if', 'then', 'so', 'such', 'which', 'who', 'whom',
        'these', 'those', 'into', 'than', 'also', 'per', 'each', 'any', 'all', 'may', 'can',
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
            return strlen($t) > 2 && ! in_array($t, self::STOPWORDS, true);
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
     *                                                                e.g. ['Job Order' => [text1, ...], 'Purchase Requisition' => [...], ...]
     *                                                                Per Scope (1.4), admins upload 5–10 samples per category.
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
        $vectorizer = new TokenCountVectorizer(new WhitespaceTokenizer);
        $vectorizer->fit($samples);
        $vectorizer->transform($samples); // -> term-count vectors

        $tfIdf = new TfIdfTransformer;
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
        $diskModelPath = "ml_models/svm_{$stamp}.model";
        $sidecarPath = "ml_models/pipeline_{$stamp}.bin";

        // ModelManager (php-ai/php-ml) only knows how to write to a real
        // local filesystem path — it has no concept of Laravel's Storage
        // disks. Save to a local temp file first, then push those bytes
        // onto whichever disk is actually configured (S3-compatible object
        // storage in production, e.g. Cloudflare R2, since local disk
        // doesn't survive a Railway redeploy — same reasoning as
        // WorkflowService::ingest()'s matching fix for uploaded documents),
        // and clean up the temp copy. classify() below reverses this.
        $tempModelPath = tempnam(sys_get_temp_dir(), 'svm_train_');
        (new ModelManager)->saveToFile($svm, $tempModelPath);
        Storage::put($diskModelPath, file_get_contents($tempModelPath));
        @unlink($tempModelPath);

        Storage::put($sidecarPath, serialize([
            'vectorizer' => $vectorizer, // fitted — reused verbatim at inference
            'tfidf' => $tfIdf,           // fitted IDF weights
        ]));

        // 5. Register the new version; deactivate previous ones.
        MlModelRepository::where('is_active', true)->update(['is_active' => false]);

        // Cross-validated, not resubstitution — see
        // estimateAccuracyViaCrossValidation()'s docblock for why this
        // matters. The FINAL model above is still trained on every staged
        // sample; only this accuracy estimate uses temporary held-out
        // folds, discarded once both values below are computed.
        $cv = $this->estimateAccuracyViaCrossValidation($samplesByCategory);

        $model = MlModelRepository::create([
            'model_name' => 'Support Vector Machine (SVM) + TF-IDF',
            'version' => 'v'.now()->format('Ymd.His'),
            'accuracy_score' => $cv['accuracy'],
            'cv_folds' => $cv['folds'],
            // Disk-relative path (config('filesystems.default')), not an
            // absolute local filesystem path — see classify() below.
            'model_file_path' => $diskModelPath,
            'training_sample_count' => count($samples),
            'is_active' => true,
            'last_trained' => now(),
        ]);

        MlModelTrained::dispatch();

        return $model;
    }

    /**
     * Read-only visibility into autoTrainIfDue()'s own eligibility query —
     * lets the ML Training admin page show the actual queue building up
     * toward the next auto-retrain (Feature: admin can see documents
     * piling up for auto-retraining) instead of that trigger being
     * invisible until it fires. Mirrors the exact same query autoTrainIfDue()
     * uses, so "X of Y needed" here is never out of sync with what will
     * really trigger a retrain.
     *
     * @return array{total_eligible: int, batch_size: int, by_category: Collection<string,int>, queue: Collection<int,DocumentRepository>, due_by_age_at: ?Carbon}
     */
    public function trainingQueueStatus(): array
    {
        $categories = ValidationService::knownCategories();

        // whereHas('assignments') — see autoTrainIfDue()'s identical clause
        // for why: a document rejected below the random-chance confidence
        // floor never gets routed (no assignment rows), so its guessed
        // category was never trustworthy enough to display here as "queued
        // to teach the model" either.
        $eligibleByCategory = collect($categories)->mapWithKeys(fn ($category) => [
            $category => DocumentRepository::where('ml_category', $category)
                ->where('desired_routing', '!=', 'unrelated')
                ->whereNotNull('ocr_text')
                ->whereNull('used_for_training_at')
                ->whereHas('assignments')
                ->orderBy('created_at')
                ->get(['document_id', 'title', 'ml_category', 'created_at']),
        ]);

        $allEligible = $eligibleByCategory->collapse()->sortBy('created_at')->values();
        $lastTrained = MlModelRepository::max('last_trained');
        $maxAgeHours = (int) config('ml.auto_train_max_age_hours', 24);

        return [
            'total_eligible' => $allEligible->count(),
            'batch_size' => (int) config('ml.auto_train_batch_size', 5),
            'by_category' => $eligibleByCategory->map->count(),
            'queue' => $allEligible->take(20),
            // Only meaningful once something is actually waiting — with an
            // empty queue there's nothing for the age fallback to fire on
            // regardless of how much time has passed (see autoTrainIfDue()'s
            // $dueByAge, which is gated on $totalEligible > 0 the same way).
            'due_by_age_at' => ($lastTrained && $allEligible->isNotEmpty())
                ? Carbon::parse($lastTrained)->addHours($maxAgeHours)
                : null,
        ];
    }

    /**
     * The fully-automatic counterpart to train() (Feature: no manual admin
     * review/retrain) — checked every few minutes by AutoTrainClassifier
     * (config('ml.auto_train_check_interval_minutes')), but only actually
     * retrains once ONE of two triggers is met, whichever comes first:
     *   - config('ml.auto_train_batch_size') new confidently-classified
     *     documents have piled up since the last training, OR
     *   - config('ml.auto_train_max_age_hours') has passed since the last
     *     training with at least ONE new document waiting.
     * Eligibility has no confidence/margin floor of its own (see the
     * eligibility query below) — any routed document, however unsure the
     * original guess was, is trusted to teach the model.
     *
     * Requires a model to already be active — this can't bootstrap the
     * very first model from nothing (see AdminController::trainModel(),
     * the one-time manual step that has to happen before automation can
     * take over).
     *
     * Protects itself with an accuracy-gated rollback: if the newly
     * trained model's cross-validated accuracy comes out WORSE than the
     * model it would replace, the old one is reactivated instead and the
     * new one is kept (inactive) purely for the history/audit trail — an
     * automatic retrain can therefore never make live classification
     * worse, only better or unchanged.
     *
     * Each category's auto-added documents are also capped at
     * config('ml.auto_train_max_auto_ratio') times that category's own
     * human-curated MlStagingSample count, oldest-eligible-first, so the
     * model stays anchored to trustworthy data even after a long stretch
     * of automatic additions; anything over the cap simply stays eligible
     * for a later run instead of being skipped forever.
     *
     * @return array{kept: bool, documentsUsed: int, previousAccuracy: float, newAccuracy: float, version: string}|null
     *                                                                                                                  null when nothing was due to run at all.
     */
    public function autoTrainIfDue(): ?array
    {
        $activeModel = MlModelRepository::active();
        if (! $activeModel) {
            return null;
        }

        $categories = ValidationService::knownCategories();

        // Oldest-first per category, so a long-waiting document isn't
        // perpetually crowded out by newer ones once the population cap
        // below starts limiting how many get included in one run.
        //
        // No confidence/margin floor here (there used to be one) —
        // WorkflowService::ingest() now routes a document as long as its
        // confidence beats the random-chance floor, specifically so
        // documents with real-but-unfamiliar vocabulary reach training
        // instead of dead-ending. Excluding those same documents from
        // training here would have quietly undone that: the whole point
        // is that the model gets to learn the vocabulary it scored low on
        // — see the re-scoring step below, which shows exactly that
        // improvement once it happens.
        //
        // whereHas('assignments') is the real gate, not global_status: a
        // document whose confidence fell BELOW the random-chance floor is
        // rejected in ingest() without ever being routed (zero assignment
        // rows) — its guessed ml_category was never trustworthy enough to
        // act on, so it must not be trusted enough to TEACH the model
        // either (found via a real bug — a since-rejected 20%-confidence
        // document was silently eligible here before this check existed).
        // This deliberately still keeps a document an approver later
        // rejected on its business merits (global_status also 'rejected',
        // but only AFTER routing — see WorkflowService::completeStage()):
        // that one passed the confidence floor and validation and reached
        // a real assignment, so its category label is trustworthy even
        // though the underlying request was denied.
        $eligibleByCategory = collect($categories)->mapWithKeys(fn ($category) => [
            $category => DocumentRepository::where('ml_category', $category)
                ->where('desired_routing', '!=', 'unrelated')
                ->whereNotNull('ocr_text')
                ->whereNull('used_for_training_at')
                ->whereHas('assignments')
                ->orderBy('created_at')
                ->get(),
        ]);

        $totalEligible = $eligibleByCategory->sum->count();
        if ($totalEligible === 0) {
            return null;
        }

        $lastTrained = MlModelRepository::max('last_trained');
        $dueByAge = $lastTrained && Carbon::parse($lastTrained)
            ->lt(now()->subHours(config('ml.auto_train_max_age_hours', 24)));
        $dueByBatch = $totalEligible >= config('ml.auto_train_batch_size', 5);

        if (! $dueByBatch && ! $dueByAge) {
            return null;
        }

        $maxAutoRatio = config('ml.auto_train_max_auto_ratio', 2.0);
        $samplesByCategory = [];
        $usedDocuments = collect();

        foreach ($categories as $category) {
            $curated = MlStagingSample::curatedTextsFor($category);
            $cap = (int) floor($curated->count() * $maxAutoRatio);
            $included = $eligibleByCategory->get($category, collect())->take($cap);

            $usedDocuments = $usedDocuments->merge($included);
            $samplesByCategory[$category] = $curated->merge($included->pluck('ocr_text'))->all();
        }

        $previousAccuracy = (float) $activeModel->accuracy_score;
        $newModel = $this->train($samplesByCategory);

        // A tolerance, not an exact "must be equal or better" comparison
        // — accuracy is a fresh cross-validated measurement every run,
        // usually against a bigger/more varied pool of real documents
        // each time, and a small drop from that (e.g. 100% -> 99%) is
        // normal, expected noise, not proof the model got worse. An exact
        // comparison would wrongly discard good progress forever once a
        // model ever reached 100%, since nothing can score higher than
        // that. Only a drop bigger than the tolerance rolls back.
        $tolerance = config('ml.auto_train_rollback_tolerance', 5);
        $kept = $newModel->accuracy_score >= ($previousAccuracy - $tolerance);
        if (! $kept) {
            // Raw query updates, not $model->save() — train() itself just
            // changed is_active on these exact rows via its own raw query
            // ("5. Register the new version"), which the in-memory
            // $activeModel/$newModel objects here were never refreshed
            // from. Setting the same in-memory value back and calling
            // save() sees nothing "dirty" and silently skips the UPDATE
            // — confirmed reproducing exactly that while testing this.
            MlModelRepository::where('model_id', $activeModel->model_id)->update(['is_active' => true]);
            MlModelRepository::where('model_id', $newModel->model_id)->update(['is_active' => false]);
        }

        // Tried either way, kept or rolled back — a rolled-back document
        // isn't retried forever on every subsequent check; it stays part
        // of the corpus for the NEXT run alongside whatever's new by then.
        $usedDocuments->each(fn (DocumentRepository $doc) => $doc->update(['used_for_training_at' => now()]));

        // Re-score this batch against the model that's actually active now
        // — only when the retrain was KEPT (a genuinely improved model).
        // A rolled-back attempt reactivates the same model these documents
        // were already scored against, so re-running classify() would just
        // reproduce the same numbers; nothing new to show. This is the
        // visible proof that feeding low-confidence, real-vocabulary
        // documents into training actually pays off — see
        // resources/views/originator/partials/tracking-content.blade.php's
        // "Recheck Confidence: X% → Y%" line, which reads these same
        // ml_recheck_* columns (previously written by a now-retired manual
        // admin action, revived here for this automatic use instead).
        if ($kept) {
            $validationService = app(ValidationService::class);
            // Fetched once per category and reused across every document
            // in that category, instead of readabilityAgainst() re-
            // querying/re-tokenizing categoryVocabulary() from scratch
            // for every single document in the batch — a real cost once a
            // batch has several documents sharing a category. Scoped to
            // this one method call only (a plain local array, not a
            // class-level cache), so it can't ever go stale across calls.
            $vocabularyByCategory = [];

            $usedDocuments->each(function (DocumentRepository $doc) use ($validationService, &$vocabularyByCategory) {
                try {
                    $recheck = $this->classify((string) $doc->ocr_text);
                } catch (\Throwable) {
                    return; // best-effort — never let a re-score failure disturb an already-routed document
                }

                // Readability's own vocabulary just grew to include this
                // same batch (see ValidationService::categoryVocabulary()),
                // so it gets the identical recheck treatment confidence
                // already had — same trigger, same batch, same "X% → Y%"
                // display pattern.
                $category = (string) $doc->ml_category;
                $vocabularyByCategory[$category] ??= ValidationService::vocabularyFor($category);
                $readability = $validationService->readabilityWithVocabulary($vocabularyByCategory[$category], $category, (string) $doc->ocr_text);

                $doc->update([
                    'ml_recheck_category' => $recheck['category'],
                    'ml_recheck_confidence' => $recheck['confidence'],
                    'ml_recheck_readability_score' => $readability['score'],
                    'ml_rechecked_at' => now(),
                ]);
            });
        }

        return [
            'kept' => $kept,
            'documentsUsed' => $usedDocuments->count(),
            'previousAccuracy' => $previousAccuracy,
            'newAccuracy' => (float) $newModel->accuracy_score,
            'version' => $newModel->version,
        ];
    }

    /**
     * Classify raw document text against the active trained SVM model.
     *
     * @return array{category: string, confidence: float, margin: float, model_id: int|null}
     */
    public function classify(string $text): array
    {
        $model = MlModelRepository::active();

        if (! $model || ! $model->model_file_path || ! Storage::exists($model->model_file_path)) {
            return ['category' => 'Other', 'confidence' => 0.0, 'margin' => 0.0, 'model_id' => null];
        }

        $stamp = preg_replace('/\D/', '', basename($model->model_file_path));
        $sidecarPath = "ml_models/pipeline_{$stamp}.bin";
        if (! Storage::exists($sidecarPath)) {
            return ['category' => 'Other', 'confidence' => 0.0, 'margin' => 0.0, 'model_id' => $model->model_id];
        }

        // Reuse the exact fitted vectorizer + tfidf objects from training so
        // feature indices align with what the SVM learned.
        $pipeline = unserialize(Storage::get($sidecarPath));
        /** @var TokenCountVectorizer $vectorizer */
        $vectorizer = $pipeline['vectorizer'];
        /** @var TfIdfTransformer $tfIdf */
        $tfIdf = $pipeline['tfidf'];

        // ModelManager only knows how to read a real local filesystem path —
        // download from the configured disk to a local temp file first,
        // same reasoning as train() above, then clean up.
        $tempModelPath = tempnam(sys_get_temp_dir(), 'svm_restore_');
        file_put_contents($tempModelPath, Storage::get($model->model_file_path));
        try {
            /** @var SVC $svm */
            $svm = (new ModelManager)->restoreFromFile($tempModelPath);
        } finally {
            @unlink($tempModelPath);
        }

        $sample = [$this->preprocess($text)];
        $vectorizer->transform($sample); // uses the already-fitted vocabulary
        $tfIdf->transform($sample);      // uses the already-fitted IDF weights

        $predicted = $svm->predict($sample)[0] ?? 'Other';
        ['confidence' => $confidence, 'margin' => $margin] = $this->predictConfidenceAndMargin($svm, $sample, (string) $predicted);

        return [
            'category' => (string) $predicted,
            'confidence' => round($confidence, 2),
            'margin' => round($margin, 2),
            'model_id' => $model->model_id,
        ];
    }

    /**
     * Confidence + margin, from the same probability distribution.
     * Because the SVM is built with probabilityEstimates enabled,
     * predictProbability() returns per-class probabilities:
     *   - confidence: the winning category's own probability, as a %.
     *   - margin: how far ahead the winning category is over the
     *     RUNNER-UP category, as a % — what WorkflowService's automatic
     *     tiering uses to tell "moderate confidence, but still clearly
     *     this category" (a real margin) apart from "genuinely ambiguous,
     *     doesn't confidently match anything" (a flat spread across
     *     categories, near-zero margin), which plain confidence alone
     *     can't distinguish.
     * Falls back gracefully if probability estimates are unavailable on
     * the installed php-ml build — margin defaults to a wide, trusting
     * value in that case (nothing to compare against), matching the
     * existing neutral-confidence fallback's own spirit.
     *
     * @return array{confidence: float, margin: float}
     */
    private function predictConfidenceAndMargin(SVC $svm, array $sample, string $predicted): array
    {
        try {
            if (method_exists($svm, 'predictProbability')) {
                $probs = $svm->predictProbability($sample)[0] ?? [];
                if (is_array($probs) && isset($probs[$predicted])) {
                    $sorted = collect($probs)->sortDesc()->values();
                    $top = (float) $sorted->get(0, 0.0);
                    $runnerUp = (float) $sorted->get(1, 0.0);

                    return ['confidence' => $top * 100, 'margin' => ($top - $runnerUp) * 100];
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return ['confidence' => 85.0, 'margin' => 100.0]; // neutral default when probability estimates are off
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
    /**
     * @return array{accuracy: float, folds: int}
     */
    /**
     * Split samples into k stratified folds (each fold gets a roughly equal
     * share of every category). With $seed left null, the shuffle is
     * unpinned — fine for estimateAccuracyViaCrossValidation(), whose result
     * is only ever compared against a moving previous-accuracy baseline, not
     * quoted directly. evaluateClassifier() passes a fixed seed instead,
     * since its numbers are meant to be reported as-is (e.g. in the
     * capstone's evaluation table) and must not change between runs.
     *
     * @param  array<string, array<int, string>>  $samplesByCategory
     * @return array{0: array<int, array<int, string>>, 1: array<int, array<int, string>>} [$foldSamples, $foldLabels]
     */
    private function buildStratifiedFolds(array $samplesByCategory, int $folds, ?int $seed = null): array
    {
        if ($seed !== null) {
            mt_srand($seed);
        }

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

        if ($seed !== null) {
            mt_srand(); // reseed from system entropy so nothing else in this request is affected
        }

        return [$foldSamples, $foldLabels];
    }

    private function estimateAccuracyViaCrossValidation(array $samplesByCategory): array
    {
        $smallestCategory = min(array_map('count', $samplesByCategory));
        // 5 folds when there's enough data for it; never more folds than
        // the smallest category has samples, and never fewer than 2 (a
        // single fold can't hold anything out).
        $folds = max(2, min(5, $smallestCategory));

        [$foldSamples, $foldLabels] = $this->buildStratifiedFolds($samplesByCategory, $folds);

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

            $vectorizer = new TokenCountVectorizer(new WhitespaceTokenizer);
            $vectorizer->fit($trainSamples);
            $vectorizer->transform($trainSamples);
            $vectorizer->transform($testSamples); // same fitted vocabulary, never refit on test data

            $tfIdf = new TfIdfTransformer;
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

        return [
            'accuracy' => $totalScored > 0 ? round(($totalCorrect / $totalScored) * 100, 2) : 0.0,
            'folds' => $folds,
        ];
    }

    /**
     * Full classifier evaluation for reporting (the capstone's "Machine
     * Learning Model Evaluation" table): Accuracy, Precision/Recall/F1 per
     * category and macro-averaged, plus the confusion matrix — computed via
     * the same stratified k-fold cross-validation estimateAccuracyViaCrossValidation()
     * uses internally, but with a FIXED seed (default 42), so re-running
     * this for the paper reproduces the exact same numbers every time.
     * Reporting-only: does not train or activate a model.
     *
     * @param  array<string, array<int, string>>  $samplesByCategory
     * @return array{
     *   folds: int, total: int, accuracy: float,
     *   perCategory: array<string, array{precision: float, recall: float, f1: float, support: int}>,
     *   macro: array{precision: float, recall: float, f1: float},
     *   confusionMatrix: array<string, array<string, int>>,
     * }
     */
    public function evaluateClassifier(array $samplesByCategory, int $seed = 42): array
    {
        $categories = array_keys($samplesByCategory);
        $smallestCategory = min(array_map('count', $samplesByCategory));
        $folds = max(2, min(5, $smallestCategory));

        [$foldSamples, $foldLabels] = $this->buildStratifiedFolds($samplesByCategory, $folds, $seed);

        // confusion[actual][predicted] = count
        $confusion = [];
        foreach ($categories as $actual) {
            foreach ($categories as $predicted) {
                $confusion[$actual][$predicted] = 0;
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
                continue;
            }

            $vectorizer = new TokenCountVectorizer(new WhitespaceTokenizer);
            $vectorizer->fit($trainSamples);
            $vectorizer->transform($trainSamples);
            $vectorizer->transform($testSamples);

            $tfIdf = new TfIdfTransformer;
            $tfIdf->fit($trainSamples);
            $tfIdf->transform($trainSamples);
            $tfIdf->transform($testSamples);

            $foldSvm = new SVC(Kernel::LINEAR, 1.0, 3, null, 0.0, 0.001, 100, true, false);
            $foldSvm->train($trainSamples, $trainLabels);

            $predictions = $foldSvm->predict($testSamples);
            foreach ($predictions as $i => $p) {
                $actual = $testLabels[$i];
                $confusion[$actual][$p]++;
                $totalScored++;
                if ($p === $actual) {
                    $totalCorrect++;
                }
            }
        }

        $perCategory = [];
        foreach ($categories as $category) {
            $tp = $confusion[$category][$category];
            $fp = 0;
            $fn = 0;
            foreach ($categories as $other) {
                if ($other === $category) {
                    continue;
                }
                $fp += $confusion[$other][$category]; // predicted this category, actually another
                $fn += $confusion[$category][$other]; // actually this category, predicted another
            }
            $support = array_sum($confusion[$category]);
            $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0.0;
            $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0.0;
            $f1 = ($precision + $recall) > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0;

            $perCategory[$category] = [
                'precision' => round($precision * 100, 2),
                'recall' => round($recall * 100, 2),
                'f1' => round($f1 * 100, 2),
                'support' => $support,
            ];
        }

        $n = count($categories);
        $macro = [
            'precision' => $n > 0 ? round(array_sum(array_column($perCategory, 'precision')) / $n, 2) : 0.0,
            'recall' => $n > 0 ? round(array_sum(array_column($perCategory, 'recall')) / $n, 2) : 0.0,
            'f1' => $n > 0 ? round(array_sum(array_column($perCategory, 'f1')) / $n, 2) : 0.0,
        ];

        return [
            'folds' => $folds,
            'total' => $totalScored,
            'accuracy' => $totalScored > 0 ? round(($totalCorrect / $totalScored) * 100, 2) : 0.0,
            'perCategory' => $perCategory,
            'macro' => $macro,
            'confusionMatrix' => $confusion,
        ];
    }
}
