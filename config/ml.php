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

];
