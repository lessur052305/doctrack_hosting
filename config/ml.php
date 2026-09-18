<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Low-confidence review queue
    |--------------------------------------------------------------------------
    |
    | A classified document with confidence below review_confidence_threshold
    | is HELD — not routed to any approver — until an admin confirms or
    | corrects its category; see WorkflowService::process() (where the hold
    | is applied) and AdminController::reviewFlaggedDocument() (where it's
    | released). Confidence never blocks the document on its own; validation
    | is the other real gate (see ValidationService).
    |
    | 70, not just "better than the 3-class chance baseline" (~33%): a wrong
    | auto-route can't be cleanly undone once an approver has acted on it
    | (no reopen path — see WorkflowService::completeStage()), while a
    | wrongly-held document only costs an admin a couple of clicks to
    | confirm. That asymmetry — cheap to review, expensive to misroute —
    | is why the bar is a confident majority, not a bare plurality.
    |
    | review_priority_threshold is a second, lower cutoff purely for display
    | — documents under it are shown first in the queue as "high priority"
    | since the model was essentially guessing.
    |
    */

    'review_confidence_threshold' => 70,
    'review_priority_threshold' => 30,

    /*
    |--------------------------------------------------------------------------
    | Automatic classification tiering (Feature: no manual admin review)
    |--------------------------------------------------------------------------
    |
    | margin_threshold: the gap (percentage points) the winning category
    | needs over the RUNNER-UP category before a document below
    | review_confidence_threshold still gets trusted automatically. Plain
    | confidence alone can't tell "moderate confidence because of
    | unfamiliar vocabulary, but still clearly this category" (e.g.
    | 45/30/25 — Job Order is still the clear leader) apart from
    | "genuinely ambiguous, doesn't confidently match anything" (e.g.
    | 35/33/32 — no real leader at all) — margin is what makes that
    | distinction. See WorkflowService::classificationTier().
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

    'margin_threshold' => 20,
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
    | The minimum fraction of a document's word tokens that must appear in
    | that category's own ML training vocabulary (built live from
    | MlStagingSample::extracted_text — see
    | ValidationService::categoryVocabulary()) before ValidationService
    | flags it as unreadable — see checkContentQuality(). This is a
    | real-word-ratio heuristic, NOT semantic understanding: it catches
    | garbled OCR output, keyboard-mashing, and non-English submissions,
    | but says nothing about whether the content is actually correct or
    | professional. A document that fails ONLY this check is held for
    | admin review rather than flatly blocked — see WorkflowService::
    | ingest() and AdminController::confirmReadabilityReview() — since
    | confirming one grows the vocabulary for next time. Matched to
    | review_confidence_threshold above so both ML gates on a document
    | (classification confidence, content readability) hold it to the
    | same bar.
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
    | belonging to any of the trained categories — no required sections, no
    | vocabulary-based readability score (both are defined per category, and
    | there's no real category to check against here), just "is there
    | actually meaningful content" so an obviously blank/garbage upload
    | still doesn't reach a human approver.
    |
    | ml_review_window_hours: how long Admin has to confirm/correct a low-
    | confidence classification before SlaService::trackLateMlReviews()
    | logs it as a late review — matches SlaService::ADMIN_REVIEW_WINDOW_HOURS,
    | the same 6-hour window already used for late auto-approval reviews,
    | not a separately invented number.
    |
    */

    'generic_min_word_count' => 20,
    'ml_review_window_hours' => 6,

];
