<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automatic classification (Feature: no manual admin review anywhere
    | in this decision)
    |--------------------------------------------------------------------------
    |
    | A document is trusted and routed automatically as long as the
    | classifier's confidence beats the random-chance floor for however
    | many categories are trained — with N categories, guessing blindly
    | already gets it right 1/N of the time, so anything at or below that
    | carries no real information. See WorkflowService::ingest()'s
    | $belowChanceFloor (computed as 100 / count(ValidationService::
    | knownCategories()), not a fixed number here, since it has to track
    | however many categories actually exist). Margin and readability are
    | still computed and stored on every document, purely for insight —
    | neither blocks routing (see ValidationService::validate()'s
    | docblock for why: both get suppressed by the exact
    | unfamiliar-vocabulary documents that most need to reach training).
    |
    | auto_train_batch_size / auto_train_max_age_hours: the two triggers
    | for the automatic retrain check (see AutoTrainClassifier) — whichever
    | comes first. Batch size reacts fast during busy periods; the age
    | ceiling guarantees a slow period never goes silent indefinitely.
    |
    | auto_train_check_interval_minutes: how often the scheduler looks for
    | either trigger — cheap (a count query), so checking often costs
    | nothing; only the retrain itself (fired when a trigger is actually
    | met) is expensive.
    |
    | auto_train_max_auto_ratio: the cap on how much of the training pool
    | can be auto-added samples, as a fraction of the ORIGINAL curated
    | seed count — keeps the model anchored to human-verified samples
    | even after a long stretch of automatic additions.
    |
    */

    'auto_train_batch_size' => 5,
    'auto_train_max_age_hours' => 24,
    'auto_train_check_interval_minutes' => 5,
    'auto_train_max_auto_ratio' => 2.0,

    // How many accuracy percentage points a retrain is allowed to drop
    // below the current active model before it's rolled back. Not zero —
    // cross-validated accuracy is a fresh measurement every run, usually
    // on a bigger/more varied pool of real documents each time, and a
    // small dip from that (e.g. 100% -> 99%) is normal, expected noise,
    // not a sign the model got worse — an exact "must be equal or
    // better" comparison would wrongly discard good progress forever
    // once a model ever reaches 100%. See ClassificationService::
    // autoTrainIfDue()'s rollback logic.
    'auto_train_rollback_tolerance' => 5,

    /*
    |--------------------------------------------------------------------------
    | Content readability heuristic
    |--------------------------------------------------------------------------
    |
    | The fraction of a document's word tokens that appear in that
    | category's own ML training vocabulary (built live from
    | MlStagingSample::extracted_text — see
    | ValidationService::categoryVocabulary()) below which
    | checkContentQuality() attaches an explanatory note. This is a
    | real-word-ratio heuristic, NOT semantic understanding: it catches
    | garbled OCR output, keyboard-mashing, and non-English submissions,
    | but says nothing about whether the content is actually correct or
    | professional. Informational only — never blocks a document (see
    | ValidationService::validate()'s docblock).
    |
    */

    'min_real_word_ratio' => 0.7,

    /*
    |--------------------------------------------------------------------------
    | Originator-directed routing (Feature: bypass the automatic pipeline)
    |--------------------------------------------------------------------------
    |
    | generic_min_word_count: the bare sanity check ValidationService::
    | validateGeneric() applies to a document the originator flagged as not
    | belonging to any of the trained categories — no required sections
    | (there's no real category to check against here), just "is there
    | actually meaningful content" so an obviously blank/garbage upload
    | still doesn't reach a human approver. It still gets a real
    | readability score against the classifier's best guess for display —
    | see ValidationService::readabilityAgainst().
    |
    */

    'generic_min_word_count' => 20,

];
