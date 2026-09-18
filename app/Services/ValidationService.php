<?php

namespace App\Services;

/**
 * ValidationService
 * ------------------
 * Implements "Automated Document Validation" (Scope 1.4): checks required
 * sections, mandatory fields, and formatting standards before a document
 * proceeds to the approval workflow (DFD Process 3.0 sub-process 3.3/3.4).
 *
 * Templates below are configurable per document category, corresponding to
 * the "Standardized Digital Document Submission" templates named in Scope
 * (Job Order, Purchase Requisition, Service Report).
 */
class ValidationService
{
    /**
     * Required section keywords each document category must contain. Each
     * entry is a list of acceptable phrasings for the SAME field, not a
     * list of separate fields — a document only needs to contain ONE of
     * them (e.g. "Job Order No" or "Job Order Number") to satisfy that
     * requirement. Added after a real document ("Job Order Number:")
     * failed validation purely over wording, not any actual missing
     * content — see validate()'s matching comment.
     */
    private const TEMPLATES = [
        'Job Order' => [
            'required_sections' => [
                ['job order no', 'job order number', 'job order #'],
                ['date requested', 'date needed', 'requested date'],
                ['requested by', 'requestor', 'requested for'],
                ['description of work', 'work description', 'scope of work'],
            ],
            'min_word_count' => 30,
        ],
        'Purchase Requisition' => [
            'required_sections' => [
                ['requisition no', 'requisition number', 'pr no', 'pr number'],
                ['department'],
                ['item description', 'items', 'description of items'],
                ['quantity', 'qty'],
                ['budget', 'estimated cost', 'estimated budget', 'total cost'],
            ],
            'min_word_count' => 20,
        ],
        'Service Report' => [
            'required_sections' => [
                ['service report no', 'service report number', 'sr no', 'report no'],
                ['technician'],
                ['date of service', 'service date', 'date serviced'],
                ['findings', 'observations'],
            ],
            'min_word_count' => 25,
        ],
    ];

    /**
     * Below this many staged training samples for a category, there isn't
     * enough real vocabulary yet to score against — mirrors
     * AdminController::TRAINING_MIN_PER_CATEGORY, the same bar the ML
     * training page already uses to decide "enough to train on."
     */
    private const MIN_VOCABULARY_SAMPLES = 5;

    /**
     * @return array{is_valid: bool, errors: array<int,string>, readability_score: ?int, readability_only_failure: bool}
     *
     * readability_only_failure is true when every OBJECTIVE check (required
     * sections present, word count met) passed and the readability
     * heuristic is the sole reason is_valid is false — see
     * WorkflowService::ingest(), which uses this to decide whether to hold
     * the document for admin review (a judgment call) instead of a flat
     * block (nothing for a human to weigh in on until the objective issue
     * is fixed).
     */
    public function validate(string $category, string $text): array
    {
        $errors = [];
        $template = self::TEMPLATES[$category] ?? null;

        if (!$template) {
            return ['is_valid' => false, 'errors' => ["Unrecognized document category: {$category}"], 'readability_score' => null, 'readability_only_failure' => false];
        }

        $normalized = strtolower($text);

        foreach ($template['required_sections'] as $variants) {
            $found = false;
            foreach ($variants as $variant) {
                if (str_contains($normalized, $variant)) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                // The first variant is the canonical display name — same
                // wording every existing error message/test already
                // expects, just now backed by a list instead of one string.
                $errors[] = "Missing required section/field: \"" . ucwords($variants[0]) . "\"";
            }
        }

        $wordCount = str_word_count($text);
        if ($wordCount < $template['min_word_count']) {
            $errors[] = "Document content is too short ({$wordCount} words; minimum {$template['min_word_count']}). Possible incomplete submission.";
        }

        $objectiveFailure = !empty($errors);

        ['error' => $qualityError, 'score' => $readabilityScore] = $this->checkContentQuality($category, $text);
        if ($qualityError) {
            $errors[] = $qualityError;
        }

        return [
            'is_valid' => empty($errors),
            'errors' => $errors,
            'readability_score' => $readabilityScore,
            'readability_only_failure' => !$objectiveFailure && $qualityError !== null,
        ];
    }

    /**
     * For a document the originator flagged as not belonging to any of
     * the trained categories (DocumentRepository::desired_routing ===
     * 'unrelated' — see WorkflowService::ingest()) — none of validate()'s
     * checks apply here: required_sections and the vocabulary-based
     * readability score are both defined per category, and there's no
     * real category to check against (the classifier's guess is kept for
     * reference only, never authoritative for this document). This is a
     * bare sanity filter instead — not "does this look like a Job
     * Order," just "is there actually meaningful content here" — so an
     * obviously blank/garbage upload still doesn't reach a human
     * approver, without pretending to validate something it can't.
     *
     * @return array{is_valid: bool, errors: array<int,string>, readability_score: ?int, readability_only_failure: bool}
     */
    public function validateGeneric(string $text): array
    {
        $minWordCount = config('ml.generic_min_word_count', 20);
        $wordCount = str_word_count($text);

        if ($wordCount < $minWordCount) {
            return [
                'is_valid' => false,
                'errors' => ["Document content is too short ({$wordCount} words; minimum {$minWordCount}). Possible incomplete submission."],
                'readability_score' => null,
                'readability_only_failure' => false,
            ];
        }

        return ['is_valid' => true, 'errors' => [], 'readability_score' => null, 'readability_only_failure' => false];
    }

    /**
     * A real-word-ratio heuristic, not semantic understanding — true
     * "is this professional/nonsense" detection isn't reliable at this
     * project's scale. This only catches text where most tokens simply
     * aren't recognizable words for this category at all (garbled OCR,
     * keyboard-mashing, wrong-language uploads).
     *
     * The vocabulary is pulled live from that category's own ML training
     * corpus (MlStagingSample::extracted_text) rather than a hand-written
     * generic English dictionary — the same admin-curated samples
     * ClassificationService already trains the classifier on double as the
     * dataset for this heuristic too, so domain vocabulary (a Job Order's
     * "truck", "brake", "transmission", etc.) is recognized without anyone
     * maintaining a word list by hand. It also means the vocabulary grows
     * every time an admin confirms a document from the readability review
     * queue (see AdminController::confirmReadabilityReview()) — that
     * confirm action stages the document into MlStagingSample exactly like
     * confirming a low-confidence classification already does.
     *
     * @return array{error: ?string, score: ?int}
     */
    private function checkContentQuality(string $category, string $text): array
    {
        $words = str_word_count(strtolower($text), 1);
        if (count($words) === 0) {
            return ['error' => null, 'score' => null]; // nothing to score — the word-count gate above already covers empty content
        }

        $vocabulary = self::categoryVocabulary($category);
        if ($vocabulary === null) {
            return ['error' => null, 'score' => null]; // too few staged training samples for this category yet
        }

        $realWordCount = count(array_filter($words, fn (string $w) => isset($vocabulary[$w])));
        $ratio = $realWordCount / count($words);
        $score = (int) round($ratio * 100);
        $threshold = config('ml.min_real_word_ratio', 0.7);

        if ($ratio >= $threshold) {
            return ['error' => null, 'score' => $score];
        }

        return [
            'error' => "Document content did not pass a basic readability check (only {$score}% recognizable words for this category). Possible garbled scan or non-English submission.",
            'score' => $score,
        ];
    }

    /**
     * @return array<string,true>|null keyed by word for O(1) lookup, or
     *         null if the category doesn't have enough staged samples yet
     *
     * Deliberately not cached — the category counts here (tens, not
     * thousands, of samples) make re-querying and re-tokenizing on every
     * call cheap, and AdminController::confirmReadabilityReview() needs
     * two genuinely fresh reads within one request (vocabulary size
     * before and after staging a new sample) to show the before/after
     * readability score — a cache would need explicit invalidation to
     * support that, for no real performance win at this scale.
     *
     * Tokenized the same way as ClassificationService::preprocess()
     * (lowercase, strip non-letters) but WITHOUT its stopword removal —
     * that stripping is right for classification's TF-IDF signal, but
     * this heuristic needs common connector words ("the", "a", "of") to
     * stay countable, since real prose naturally contains them.
     */
    private static function categoryVocabulary(string $category): ?array
    {
        $samples = \App\Models\MlStagingSample::where('category', $category)->pluck('extracted_text');
        if ($samples->count() < self::MIN_VOCABULARY_SAMPLES) {
            return null;
        }

        $vocabulary = [];
        foreach ($samples as $text) {
            $normalized = preg_replace('/[^a-z\s]/', ' ', strtolower($text));
            foreach (preg_split('/\s+/', trim($normalized)) ?: [] as $token) {
                if (strlen($token) >= 2) {
                    $vocabulary[$token] = true;
                }
            }
        }

        return $vocabulary;
    }

    /** How many distinct words are currently recognized for a category — used to report vocabulary growth after a readability-review confirm (see AdminController::confirmReadabilityReview()). */
    public static function vocabularySize(string $category): int
    {
        return count(self::categoryVocabulary($category) ?? []);
    }

    public static function knownCategories(): array
    {
        return array_keys(self::TEMPLATES);
    }
}
