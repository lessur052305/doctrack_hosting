<?php

namespace App\Services;

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use Carbon\CarbonInterval;

/**
 * A statistical estimate for most of the pipeline, upgraded to a real
 * trained regression (ApprovalTimeMlService) for the immediate next
 * decision once a (category, department) combo has enough real history
 * to train on — see that service's docblock. Stages beyond the immediate
 * next one always use the plain average below, since we don't know in
 * advance which department will end up handling those.
 */
class ApprovalForecastService
{
    /**
     * Below this many GENUINELY non-zero (real business-hours) decisions,
     * the plain average isn't trustworthy — same reasoning as
     * ApprovalTimeMlService's own training floor, just a smaller bar
     * since this is a plain average, not a trained model, and it already
     * has a safe fallback (the SLA deadline) to lean on instead. Matches
     * PerformanceInsightsService::MIN_DECISIONS for consistency — both
     * exist to stop a single lucky (or entirely absent) real reading from
     * being trusted as "the average."
     */
    private const MIN_NON_ZERO_DECISIONS = 3;

    public function __construct(
        private BusinessHoursService $businessHours,
        private ApprovalTimeMlService $timeMl,
    ) {
    }

    /**
     * Null whenever there isn't enough signal to say anything useful: no
     * category yet, no historical decisions for that category, or the
     * document has nothing left to approve.
     *
     * Every configured stage is routed simultaneously, not one after
     * another (see WorkflowService::routeToWorkflow() — "a document can
     * therefore have more than one stage pending at once"), and stages
     * can be decided in any order (completeStage()'s "out-of-order
     * resolution"). So the document isn't done until the SLOWEST of its
     * currently-unresolved stages finishes — this estimates each one
     * independently and takes the largest, rather than adding every
     * stage's typical time together as if they took turns.
     */
    public function estimateFor(DocumentRepository $document): ?CarbonInterval
    {
        if (!$document->ml_category) {
            return null;
        }

        // A custom-routed document (Feature: originator-directed routing
        // — see WorkflowService::routeToCustomApprovers()) never went
        // through the category's own pipeline, so there's no "typical
        // processing time for this category" to estimate against —
        // averaging in that history would be meaningless at best, and at
        // worst would mix in unrelated one-off stages from OTHER
        // documents that happen to share the same guessed category (this
        // query joins on ml_category alone, with nothing scoping it to
        // real configured stages only). It already has a known, exact
        // deadline instead of a statistical guess — use that directly.
        // Checked against the two explicit opt-out values, not "!== 'auto'"
        // — desired_routing defaults to 'auto' at the DB level, but a
        // freshly create()'d model in memory (never refetched) reads that
        // column as null rather than the DB default until it's actually
        // reloaded, and null !== 'auto' would otherwise misfire this
        // branch for a perfectly normal document.
        if (in_array($document->desired_routing, ['custom', 'unrelated'], true)) {
            return $this->customRoutingDeadline($document);
        }

        $stages = WorkflowStage::configured()->forCategory($document->ml_category)->where('is_archived', false)->get();
        if ($stages->isEmpty()) {
            return null;
        }

        $unresolvedStages = $this->unresolvedStages($document, $stages);
        if ($unresolvedStages->isEmpty()) {
            return null; // every stage already resolved
        }

        $maxSeconds = null;
        foreach ($unresolvedStages as $stage) {
            $stageSeconds = $this->estimateStageSeconds($document, $stage);
            if ($stageSeconds !== null && ($maxSeconds === null || $stageSeconds > $maxSeconds)) {
                $maxSeconds = $stageSeconds;
            }
        }

        return $maxSeconds !== null ? CarbonInterval::seconds((int) round($maxSeconds)) : null;
    }

    /**
     * How long THIS ONE stage is expected to take to resolve, in
     * isolation from every other stage — null if there's no historical
     * decision data to base it on. See estimateFor()'s docblock for why
     * the whole-document estimate is the LARGEST of these calls, not
     * their sum.
     */
    private function estimateStageSeconds(DocumentRepository $document, WorkflowStage $stage): ?float
    {
        $eligibleApprovers = $this->eligibleApproversFor($document, $stage);

        // Scope the historical average to whichever department(s) are
        // actually eligible for this stage, so a fast department's
        // documents aren't dragged down by averaging in a slower
        // department's history (and vice versa). Falls back to the whole
        // category when department isn't set for any eligible approver,
        // rather than filtering everything out.
        $departments = $eligibleApprovers->pluck('department')->filter()->unique()->values();

        // Averaged in PHP rather than via a DB-side date-diff function —
        // MySQL's TIMESTAMPDIFF has no portable equivalent on the sqlite
        // driver the test suite runs on, and the historical dataset this
        // averages over is small enough that pulling it into PHP costs
        // nothing.
        // Selected as rows, not plucked into a created_at-keyed map — sibling
        // seats on the same stage batch share an identical created_at (see
        // WorkflowService::assignStage()), and a keyed pluck would silently
        // collapse those duplicates down to one.
        $decisionsQuery = DocumentAssignment::query()
            ->join('document_repository', 'document_assignments.document_id', '=', 'document_repository.document_id')
            ->where('document_repository.ml_category', $document->ml_category)
            ->whereNotNull('document_assignments.acted_at')
            // An auto-approval measures how long the SLA deadline happened
            // to be, not how fast a person actually decided — mixing those
            // into the average would drag the "typical" time toward the
            // deadline itself as more documents auto-approve, instead of
            // reflecting real human speed.
            ->where('document_assignments.auto_approved', false)
            // Approvals only, not rejections — "Estimated Approval Time"
            // means how long it takes this approver to say yes, and a
            // reject isn't the same behavior (a quick "this is wrong"
            // measures something different from genuine review-then-
            // approve time). Mixing them in would blur what the average
            // actually represents.
            ->where('document_assignments.individual_status', 'approved');

        if ($departments->isNotEmpty()) {
            $decisionsQuery->join('users', 'document_assignments.user_id', '=', 'users.user_id')
                ->whereIn('users.department', $departments);
        }

        $decisions = $decisionsQuery->get(['document_assignments.created_at', 'document_assignments.acted_at']);

        // Business-hours-aware, not a raw wall-clock diff — otherwise a
        // document that sat untouched over a weekend before a same-morning
        // decision reads as "took 2 days" instead of "took 10 minutes,"
        // same reasoning as every other elapsed-time calculation in this
        // app (see BusinessHoursService).
        $elapsedSeconds = $decisions->map(
            fn ($row) => $this->businessHours->businessSecondsRemaining(
                \Carbon\Carbon::parse($row->created_at),
                \Carbon\Carbon::parse($row->acted_at)
            )
        );

        // No historical decisions yet — OR fewer than MIN_NON_ZERO_DECISIONS
        // GENUINELY non-zero ones (not just "not literally every one is
        // 0"). A decision measures 0 whenever it was both routed AND
        // decided entirely outside business hours (e.g. same-evening
        // testing) — real wall-clock time passed, but none of it was
        // business time. A handful of real readings mixed into a mostly-
        // zero sample would otherwise still get averaged in and quietly
        // drag the result toward that same misleading near-0, just less
        // extremely. Either way there's nothing trustworthy to average or
        // feed the ML model with, but the stage's own approver(s) still
        // have a real, known SLA deadline (not the document's due_date —
        // that's an administrative field; sla_expires_at is the actual
        // cutoff an approver is bound to act within). Same "fall back to
        // the one real number we do have" reasoning as
        // customRoutingDeadline(), just scoped to this one stage instead
        // of the whole document.
        $nonZeroCount = $elapsedSeconds->filter(fn ($s) => $s > 0)->count();
        if ($decisions->isEmpty() || $nonZeroCount < self::MIN_NON_ZERO_DECISIONS) {
            return $this->stageSlaFallbackSeconds($document, $stage);
        }

        $avgSeconds = $elapsedSeconds->avg();

        // Unanimous approval means the stage waits on its SLOWEST eligible
        // approver, not its fastest — so the queue-depth padding uses the
        // most backed-up approver among this stage's eligible pool.
        $queueDepth = $eligibleApprovers
            ->map(fn (User $approver) => DocumentAssignment::where('user_id', $approver->user_id)
                ->where('individual_status', 'pending')
                ->where('document_id', '!=', $document->document_id)
                ->count())
            ->max() ?? 0;

        // The trained model only ever covers ONE specific (category,
        // department) combo — only usable when this stage's eligible pool
        // resolves to exactly one department; a stage jointly owned by
        // multiple departments, or with no department set at all, keeps
        // using the plain average, same as before ML existed.
        $mlPrediction = $departments->count() === 1
            ? $this->timeMl->predictNextDecision($document->ml_category, $departments->first(), $eligibleApprovers)
            : null;

        // ML's own prediction replaces the average for THIS stage's own
        // decision only; the queue-depth padding still uses the plain
        // average as its unit either way — ML doesn't know about backlog,
        // there's nothing to double-count.
        return $mlPrediction !== null
            ? $mlPrediction + $avgSeconds * $queueDepth
            : $avgSeconds * (1 + $queueDepth);
    }

    /**
     * A custom-routed document's "estimate" is just its real deadline —
     * approval needs every hand-picked approver to agree, so the
     * relevant date is whichever of their SLA windows runs longest, not
     * an average of anyone else's. Null while still awaiting the
     * originator's own approver pick (no assignments exist yet — see
     * WorkflowService::routeOrAwaitApproverSelection()) or once every
     * seat has already been decided (nothing left to wait on).
     */
    private function customRoutingDeadline(DocumentRepository $document): ?CarbonInterval
    {
        $latestDeadline = $document->assignments()->where('individual_status', 'pending')->max('sla_expires_at');
        if (!$latestDeadline) {
            return null;
        }

        // businessSecondsRemaining(), not a plain wall-clock diff — the
        // blade re-expands this figure back into a date via
        // addBusinessMinutes(), which is its exact mirror image (see that
        // method's own docblock). A raw wall-clock diff fed into that
        // mismatched "business minutes" expansion overshoots the real
        // deadline by treating every hour as if it were a working hour,
        // and only ever looked right because of the blade's own separate
        // "never show later than the due date" clamp masking the overshoot.
        $seconds = $this->businessHours->businessSecondsRemaining(now(), \Carbon\Carbon::parse($latestDeadline));

        return CarbonInterval::seconds(max(0, $seconds));
    }

    /**
     * A stage's fallback estimate when there's no historical decision
     * data to average or feed the ML model with — the real SLA deadline
     * for that stage's own pending seat(s), not the document's due_date
     * (see estimateStageSeconds()'s call site for why). Null if this
     * stage hasn't been routed yet (no pending seats -> no SLA window
     * exists for it yet either).
     */
    private function stageSlaFallbackSeconds(DocumentRepository $document, WorkflowStage $stage): ?float
    {
        $latestDeadline = $document->assignments()
            ->where('stage_id', $stage->stage_id)
            ->where('individual_status', 'pending')
            ->max('sla_expires_at');

        if (!$latestDeadline) {
            return null;
        }

        // businessSecondsRemaining(), not a plain wall-clock diff — same
        // reasoning as customRoutingDeadline() just above.
        return $this->businessHours->businessSecondsRemaining(now(), \Carbon\Carbon::parse($latestDeadline));
    }

    /**
     * Every stage that still has work left — every stage with a pending
     * seat, or (if the document hasn't been routed yet at all) every
     * configured stage, since they'll all be routed together the moment
     * it is. Empty once every stage has been resolved.
     */
    private function unresolvedStages(DocumentRepository $document, \Illuminate\Support\Collection $stages): \Illuminate\Support\Collection
    {
        $pendingStageIds = $document->assignments
            ->where('individual_status', 'pending')
            ->pluck('stage_id')
            ->unique();

        if ($pendingStageIds->isNotEmpty()) {
            return $stages->whereIn('stage_id', $pendingStageIds)->values();
        }

        // Not yet routed (no assignments at all) -> the whole pipeline is
        // still ahead of it.
        return $document->assignments->isEmpty() ? $stages->values() : collect();
    }

    /** Mirrors WorkflowService::eligibleApproversForStage()'s filters. */
    private function eligibleApproversFor(DocumentRepository $document, WorkflowStage $stage): \Illuminate\Support\Collection
    {
        return User::where('role', 'approver')
            ->where('is_active', true)
            ->where('assigned_category', $document->ml_category)
            ->get()
            ->filter(function (User $approver) use ($stage) {
                $assignedStageIds = $approver->workflowStages()->pluck('workflow_stages.stage_id');
                return $assignedStageIds->isEmpty() || $assignedStageIds->contains($stage->stage_id);
            })
            ->values();
    }
}
