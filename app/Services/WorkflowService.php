<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\NotificationRecord;
use App\Models\User;
use App\Models\WorkflowStage;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * WorkflowService
 * ----------------
 * Orchestrates the full document lifecycle state machine described in
 * Section 5:
 *
 *   processing -> classified_validated -> approved   (or auto_approved)
 *                                       -> rejected
 *
 * SINGLE-ASSIGNMENT, LOAD-BALANCED APPROVAL MODEL: every configured stage
 * for a document's category is routed the moment the document is uploaded
 * (see routeToWorkflow()) — not one at a time. Each stage is routed to
 * exactly ONE eligible approver: whichever eligible approver currently has
 * the fewest pending assignments (see selectApproverForStage()). This
 * guarantees a document is never visible in more than one approver's
 * queue for the same stage at the same time, so two approvers can never
 * act on — or race to approve — the same stage.
 *
 * Because every stage is assigned up front, a document can have more than
 * one stage pending at once (e.g. Stage 2 assigned to a specialist while
 * Stage 1 is still awaiting a different approver's decision). Stages
 * resolve independently of each other and of sequence order; the document
 * only finalizes once every stage's assignment has been resolved (see
 * completeStage()).
 *
 * ELIGIBILITY: an approver is eligible for a stage if (a) their
 * assigned_category matches the document, and (b) either they have no
 * specific stage restrictions at all (eligible for every stage in their
 * category by default) or this stage is explicitly among their assigned
 * stages (User::workflowStages()). Among eligible approvers, the one
 * currently carrying the least workload (fewest pending assignments) is
 * selected; ties are broken by fairness (whoever's last assignment was
 * longest ago), and approvers marked busy/away are skipped unless doing so
 * would leave nobody eligible at all.
 *
 * Example: if Approver A covers stages 1–3 and Approver B is dedicated to
 * stage 2 only, stage 2 goes to whichever of them has fewer pending
 * documents at that moment — including Approver B outright if Approver A
 * just picked up stage 1 for this same document a moment earlier, since
 * that pending assignment already counts against them.
 *
 * Each assignment's SLA window is 25% of the minutes remaining until the
 * document's own absolute due_date, computed at minute granularity so it
 * scales smoothly rather than in coarse hour jumps. Due dates at or under
 * 1 hour away skip the percentage and get a flat 15-minute window instead.
 * Either way, the window never extends past the due_date itself.
 */
class WorkflowService
{
    /** Portion of the document's remaining time allotted to each stage's approvers, once past the short-due threshold. */
    private const APPROVER_SLA_FRACTION = 0.25;

    /**
     * Due dates at or under this many minutes away skip the percentage
     * calculation entirely and get a flat SLA window instead (see
     * FIXED_SHORT_DUE_SLA_MINUTES) — 25% of anything that short leaves an
     * approver with only a few minutes, which isn't a workable review
     * window in practice.
     */
    private const SHORT_DUE_THRESHOLD_MINUTES = 60;

    /** Flat SLA window used for due dates at or under the short-due threshold above. */
    private const FIXED_SHORT_DUE_SLA_MINUTES = 15;

    /** Below this many extracted characters, treat it as an extraction failure, not "short content". */
    private const MIN_EXTRACTED_CHARS = 40;

    public function __construct(
        private TextExtractionService $extractor,
        private ClassificationService $classifier,
        private ValidationService $validator,
    ) {
    }

    /**
     * Handles Staff (Originator) document submission end-to-end:
     * Process 3.1 -> 3.2 -> 3.3 -> 3.4 -> 4.0 in one pass.
     *
     * @param  int|null  $batchId  Links this document to the SubmissionBatch
     *                             it was uploaded alongside (Feature: grouped
     *                             approval requests), so the Approver and
     *                             Admin SLA dashboards can nest documents
     *                             submitted together under one container.
     */
    public function ingest(UploadedFile $file, User $originator, string $dueDate, ?int $batchId = null): DocumentRepository
    {
        return DB::transaction(function () use ($file, $originator, $dueDate, $batchId) {
            $storedPath = $file->store('documents', 'local');

            $document = DocumentRepository::create([
                'originator_id' => $originator->user_id,
                'batch_id' => $batchId,
                'title' => $file->getClientOriginalName(),
                'file_path' => $storedPath,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'due_date' => $dueDate,
                'global_status' => 'processing',
            ]);

            AuditLog::record($originator->user_id, $document->document_id, 'upload', "Document '{$document->title}' submitted.");

            // 3.1 + 3.2 — extraction & preprocessing
            $extraction = $this->extractor->extract($file);
            $document->ocr_text = $extraction['text'];
            $document->used_ocr_fallback = $extraction['used_ocr_fallback'];

            // If extraction genuinely failed to produce readable text (as
            // opposed to the document just being short), say so plainly
            // instead of running it through category validation and
            // surfacing a confusing "0 words; minimum 30" message.
            if (mb_strlen(trim($extraction['text'])) < self::MIN_EXTRACTED_CHARS) {
                $document->ml_category = null;
                $document->ml_confidence = 0;
                $document->is_validated = false;
                $document->validation_errors = [
                    'Could not extract readable text from this file. If it is a scanned image or a non-searchable PDF, ' .
                    'OCR/PDF-parsing support may not be installed on this system — contact your Administrator, or try ' .
                    're-uploading as a plain text (.txt) or Word (.docx) file instead.',
                ];
                $document->global_status = 'processing';
                $document->save();

                AuditLog::record(null, $document->document_id, 'extraction_failed',
                    "Text extraction produced no usable content for '{$document->title}' " .
                    "(mime: {$document->mime_type}). Classification and validation were skipped.");

                NotificationRecord::send($originator->user_id, $document->document_id,
                    "Your document '{$document->title}' could not be read by the system. " . $document->validation_errors[0]);

                return $document->fresh();
            }

            // 3.3 — classification
            $result = $this->classifier->classify($extraction['text']);
            $document->ml_category = $result['category'];
            $document->ml_confidence = $result['confidence'];
            $document->model_id = $result['model_id'];

            AuditLog::record(null, $document->document_id, 'classify',
                "Classified as '{$result['category']}' (confidence {$result['confidence']}%)" .
                ($extraction['used_ocr_fallback'] ? ' [OCR fallback used]' : ''));

            // 3.4 — validation
            $validation = $this->validator->validate($result['category'], $extraction['text']);
            $document->is_validated = $validation['is_valid'];
            $document->validation_errors = $validation['errors'];

            $document->global_status = $validation['is_valid'] ? 'classified_validated' : 'processing';
            $document->save();

            AuditLog::record(null, $document->document_id, 'validate',
                $validation['is_valid'] ? 'Validation passed.' : 'Validation failed: ' . implode('; ', $validation['errors']));

            if ($validation['is_valid']) {
                $this->routeToWorkflow($document);
            } else {
                NotificationRecord::send($originator->user_id, $document->document_id,
                    "Your document '{$document->title}' failed validation: " . implode('; ', $validation['errors']));
            }

            return $document->fresh();
        });
    }

    /**
     * Process 4.0 — Workflow Routing.
     *
     * Every configured stage is assigned to its own single, load-balanced
     * approver immediately at upload time (Feature: all stages routed up
     * front, not one at a time). Stages are processed in sequence_order so
     * that workload counts accumulate correctly within this same routing
     * pass — e.g. if the same approver is picked for stage 1, that pending
     * assignment already counts against them when stage 2 is routed a
     * moment later, so a second eligible approver with less on their plate
     * (or one dedicated to just that stage) can take it instead.
     *
     * A document can therefore have more than one stage pending at once;
     * see completeStage() for how out-of-order resolution and final
     * document approval are handled.
     */
    public function routeToWorkflow(DocumentRepository $document): void
    {
        $stages = WorkflowStage::forCategory($document->ml_category)->get();

        if ($stages->isEmpty()) {
            // No configured pipeline for this category — create a single generic stage.
            $stages = collect([WorkflowStage::firstOrCreate(
                ['document_category' => $document->ml_category, 'sequence_order' => 1],
                ['stage_name' => 'General Review']
            )]);
        }

        foreach ($stages as $stage) {
            $this->assignStage($document, $stage);
        }
    }

    /**
     * Every eligible approver for a specific stage: matching category,
     * active account, and either unrestricted (no specific stage picks —
     * eligible for every stage in their category by default) or
     * explicitly assigned to this stage. Does NOT pick a single winner —
     * see selectApproverForStage() for the load-balanced pick from this pool.
     */
    private function eligibleApproversForStage(DocumentRepository $document, WorkflowStage $stage): Collection
    {
        return User::where('role', 'approver')
            ->where('is_active', true)
            ->where('assigned_category', $document->ml_category)
            ->get()
            ->filter(function (User $approver) use ($stage) {
                $assignedStageIds = $approver->workflowStages()->pluck('workflow_stages.stage_id');
                // No explicit stage picks -> eligible for every stage in their category (default).
                return $assignedStageIds->isEmpty() || $assignedStageIds->contains($stage->stage_id);
            })
            ->values();
    }

    /**
     * Selects exactly ONE approver for a stage (Feature: single-assignment
     * load balancing): whichever eligible approver currently has the
     * fewest pending assignments across all documents/stages. This is what
     * guarantees a document is routed to exactly one approver's queue at a
     * time instead of racing across multiple approvers, and lets an
     * approver dedicated to a specific stage take it over a busier
     * unrestricted peer even mid-pipeline.
     *
     * Approvers marked busy/away are skipped in favor of an available
     * peer, unless every eligible approver is busy — an approver who
     * forgot to toggle back "available" should never permanently block a
     * document.
     *
     * Ties in workload are broken by fairness, not by an arbitrary ID:
     * whichever tied approver's most recent assignment (of any status)
     * happened longest ago gets this one — "the approver who gets a
     * document first" rather than whoever "gets a document recently".
     * An approver who has never received an assignment is treated as
     * having waited the longest and wins the tie outright.
     */
    private function selectApproverForStage(DocumentRepository $document, WorkflowStage $stage): ?User
    {
        $candidates = $this->eligibleApproversForStage($document, $stage);

        if ($candidates->isEmpty()) {
            return null;
        }

        $available = $candidates->reject(fn (User $approver) => $approver->is_busy)->values();
        $pool = $available->isNotEmpty() ? $available : $candidates;
        $userIds = $pool->pluck('user_id');

        $workloads = DocumentAssignment::whereIn('user_id', $userIds)
            ->where('individual_status', 'pending')
            ->selectRaw('user_id, count(*) as active_count')
            ->groupBy('user_id')
            ->pluck('active_count', 'user_id');

        $lastAssignedAt = DocumentAssignment::whereIn('user_id', $userIds)
            ->selectRaw('user_id, MAX(created_at) as last_assigned_at')
            ->groupBy('user_id')
            ->pluck('last_assigned_at', 'user_id');

        $ranked = $pool->values()->all();
        usort($ranked, function (User $a, User $b) use ($workloads, $lastAssignedAt) {
            $countA = (int) ($workloads[$a->user_id] ?? 0);
            $countB = (int) ($workloads[$b->user_id] ?? 0);
            if ($countA !== $countB) {
                return $countA <=> $countB;
            }

            // Tie on workload: fairness tie-break by who's waited longest
            // since their last assignment (never-assigned sorts first).
            $lastA = $lastAssignedAt[$a->user_id] ?? null;
            $lastB = $lastAssignedAt[$b->user_id] ?? null;

            if ($lastA === null && $lastB === null) {
                return $a->user_id <=> $b->user_id; // final deterministic fallback
            }
            if ($lastA === null) {
                return -1;
            }
            if ($lastB === null) {
                return 1;
            }

            return strcmp($lastA, $lastB);
        });

        return $ranked[0] ?? null;
    }

    /**
     * The 25% Rule, with a short-due exception: due dates more than 1 hour
     * away get 25% of the remaining time as the approvers' SLA window
     * (computed in minutes, not whole hours, so it stays proportional
     * rather than collapsing to a flat value for anything under ~10
     * hours). Due dates at or under 1 hour away skip the percentage
     * entirely and get a flat 15-minute window instead — 25% of a due date
     * that close would only be a few minutes, not a workable review
     * window. Either way, the window is still clamped to never extend past
     * the document's own absolute due date.
     */
    private function computeApproverSlaExpiry(DocumentRepository $document): Carbon
    {
        $dueDate = Carbon::parse($document->due_date);
        $totalMinutesLeft = now()->diffInMinutes($dueDate, false); // signed: negative if already overdue

        $approverSlaMinutes = $totalMinutesLeft <= self::SHORT_DUE_THRESHOLD_MINUTES
            ? self::FIXED_SHORT_DUE_SLA_MINUTES
            : (int) round($totalMinutesLeft * self::APPROVER_SLA_FRACTION);

        $slaExpiresAt = now()->copy()->addMinutes($approverSlaMinutes);

        // Safety guard: never let the approver's window extend past the
        // document's own absolute due date.
        if ($slaExpiresAt->greaterThan($dueDate)) {
            $slaExpiresAt = $dueDate->copy();
        }

        return $slaExpiresAt;
    }

    /**
     * Creates exactly ONE DocumentAssignment for this stage — the eligible
     * approver currently carrying the fewest pending assignments (Feature:
     * single-assignment load balancing; see selectApproverForStage()). A
     * document is therefore only ever visible in one approver's queue at a
     * time for a given stage.
     */
    private function assignStage(DocumentRepository $document, WorkflowStage $stage): void
    {
        $approver = $this->selectApproverForStage($document, $stage);

        if (!$approver) {
            AuditLog::record(null, $document->document_id, 'route_no_approver',
                "No active approver is eligible for stage '{$stage->stage_name}' (category '{$document->ml_category}'). " .
                'An Admin must create/assign an approver for this category and stage.');

            foreach (User::where('role', 'admin')->where('is_active', true)->get() as $admin) {
                NotificationRecord::send($admin->user_id, $document->document_id,
                    "'{$document->title}' is stuck at stage '{$stage->stage_name}' — no eligible approver is available.",
                    'high');
            }
            return;
        }

        $slaExpiresAt = $this->computeApproverSlaExpiry($document);
        $priorityRank = $this->computePriority($document->due_date);

        DocumentAssignment::create([
            'document_id' => $document->document_id,
            'user_id' => $approver->user_id,
            'stage_id' => $stage->stage_id,
            'due_date' => $document->due_date,
            'priority_rank' => $priorityRank,
            'individual_status' => 'pending',
            'sla_expires_at' => $slaExpiresAt,
        ]);

        NotificationRecord::send($approver->user_id, $document->document_id,
            "New document assigned for '{$stage->stage_name}': {$document->title}.");

        AuditLog::record(null, $document->document_id, 'route',
            "Stage '{$stage->stage_name}': assigned to {$approver->full_name} — least active workload among eligible approvers " .
            "(category '{$document->ml_category}'). SLA window expires {$slaExpiresAt->toDayDateTimeString()}.");
    }

    private function computePriority($dueDate): int
    {
        if (!$dueDate) return 2;
        $hoursLeft = now()->diffInHours($dueDate, false);
        if ($hoursLeft <= 24) return 1;   // Urgent
        if ($hoursLeft <= 72) return 2;   // Normal
        return 3;                        // Low
    }

    /** Process 5.0 — Approval Management. Approver decision on their assignment. */
    public function decide(DocumentAssignment $assignment, User $approver, string $decision, ?string $comments = null): void
    {
        DB::transaction(function () use ($assignment, $approver, $decision, $comments) {
            $assignment->individual_status = $decision; // 'approved' | 'rejected'
            $assignment->comments = $comments;
            $assignment->acted_at = now();
            $assignment->save();

            AuditLog::record($approver->user_id, $assignment->document_id, $decision,
                "Stage '{$assignment->stage->stage_name}' {$decision} by {$approver->full_name}." . ($comments ? " Comments: {$comments}" : ''));

            $this->completeStage($assignment, $decision);
        });
    }

    /**
     * Resolves a stage once its single assignment has been decided (by an
     * approver in decide(), by an Admin in SlaService::adminOverride(), or
     * automatically by SlaService::autoApproveUnresolved()).
     *
     * @param  bool  $auto  true when this resolution came from the SLA
     *                      auto-approval safety net rather than a human
     *                      decision — used only to pick the correct
     *                      terminal global_status ('approved' vs 'auto_approved').
     */
    public function completeStage(DocumentAssignment $assignment, string $decision, bool $auto = false): void
    {
        $document = $assignment->document;
        $stage = $assignment->stage;

        if ($decision === 'rejected') {
            // Rejection terminates the WHOLE document — close every other
            // pending assignment across ALL stages, since every stage is
            // routed up front and more than one can be pending at once.
            DocumentAssignment::where('document_id', $document->document_id)
                ->where('individual_status', 'pending')
                ->where('assignment_id', '!=', $assignment->assignment_id)
                ->get()
                ->each(function (DocumentAssignment $other) {
                    $other->individual_status = 'rejected';
                    $other->comments = 'Auto-closed — document rejected at another stage.';
                    $other->acted_at = now();
                    $other->save();
                });

            $document->global_status = 'rejected';
            $document->save();
            NotificationRecord::send($document->originator_id, $document->document_id,
                "Your document '{$document->title}' was rejected at stage '{$stage->stage_name}'.");
            return;
        }

        // Safety net only: every stage is normally already assigned at
        // upload time (see routeToWorkflow()). This only fires if a stage
        // was added to the category's pipeline after this document was
        // already routed, so it still gets picked up.
        $nextStage = WorkflowStage::where('document_category', $document->ml_category)
            ->where('sequence_order', '>', $stage->sequence_order)
            ->orderBy('sequence_order')
            ->first();

        if ($nextStage) {
            $alreadyAssigned = DocumentAssignment::where('document_id', $document->document_id)
                ->where('stage_id', $nextStage->stage_id)
                ->exists();

            if (!$alreadyAssigned) {
                $this->assignStage($document, $nextStage);
            }
        }

        // Finalize only once NO stage anywhere for this document still has
        // a pending assignment — not merely "nothing comes after this
        // stage" — since stages can be completed out of sequence order.
        $anyPending = DocumentAssignment::where('document_id', $document->document_id)
            ->where('individual_status', 'pending')
            ->exists();

        if (!$anyPending) {
            $document->global_status = $auto ? 'auto_approved' : 'approved';
            $document->save();
            NotificationRecord::send($document->originator_id, $document->document_id,
                "Your document '{$document->title}' has been fully approved." . ($auto ? ' (auto-approved by the system after an SLA timeout)' : ''));
            AuditLog::record(null, $document->document_id, 'finalize',
                'All stages approved — document archived to repository.' . ($auto ? ' [Final stage was auto-approved after SLA timeout]' : ''));
        }
    }
}