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
 * SINGLE-ASSIGNMENT, LOAD-BALANCED APPROVAL MODEL: when a document enters
 * a stage, it is routed to exactly ONE eligible approver for that stage —
 * whichever eligible approver currently has the fewest pending assignments
 * (see selectApproverForStage()). This guarantees a document is never
 * visible in more than one approver's queue at the same time, so two
 * approvers can never act on — or race to approve — the same document.
 *
 * ELIGIBILITY: an approver is eligible for a stage if (a) their
 * assigned_category matches the document, and (b) either they have no
 * specific stage restrictions at all (eligible for every stage in their
 * category by default) or this stage is explicitly among their assigned
 * stages (User::workflowStages()). Among eligible approvers, the one
 * currently carrying the least workload (fewest pending assignments) is
 * selected; approvers marked busy/away are skipped unless doing so would
 * leave nobody eligible at all, in which case busy status is ignored
 * rather than leaving the document unassigned.
 *
 * Example: if Approver A covers stages 1 and 2, and Approver B covers
 * stages 2 and 3, stage 2 goes to whichever of them has fewer pending
 * documents at that moment — not to both.
 *
 * SOLO-APPROVER SHORTCUT: if the exact same single approver is the ONLY
 * eligible candidate for every stage in the pipeline (nobody else could
 * ever take over a later stage), all stages are assigned to them
 * immediately at upload time instead of one at a time — since the same
 * person handles every stage regardless of order, there's no reason to
 * hide later stages. Otherwise stages are entered one at a time,
 * just-in-time, as each prior stage resolves.
 *
 * Each assignment's SLA window is computed as 25% of the hours remaining
 * until the document's own absolute due_date (min 2 hours, never
 * extending past the due_date itself).
 */
class WorkflowService
{
    /** Portion of the document's remaining time allotted to each stage's approvers. */
    private const APPROVER_SLA_FRACTION = 0.25;

    /** Floor for the approver SLA window, unless the due date doesn't allow it. */
    private const MIN_APPROVER_SLA_HOURS = 2;

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
     * If a single approver is the ONLY eligible candidate for every stage
     * in the pipeline, all stages are assigned to them immediately.
     * Otherwise only the FIRST stage is entered now, routed to whichever
     * eligible approver currently has the fewest pending assignments;
     * later stages are entered dynamically as each prior one resolves
     * (see completeStage()).
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

        $soloApprover = $this->soleEligibleApproverAcrossAllStages($document, $stages);

        if ($soloApprover) {
            foreach ($stages as $stage) {
                $this->assignStage($document, $stage, $soloApprover);
            }
        } else {
            $this->assignStage($document, $stages->first());
        }
    }

    /**
     * Returns the one approver if — and only if — they are the SOLE
     * eligible candidate (not merely the busiest/least-busy pick) for
     * every stage in the pipeline, meaning nobody else could ever take
     * over a later stage regardless of workload. Returns null otherwise,
     * in which case each stage is routed independently via
     * selectApproverForStage() as it is entered.
     */
    private function soleEligibleApproverAcrossAllStages(DocumentRepository $document, Collection $stages): ?User
    {
        $firstStageCandidates = $this->eligibleApproversForStage($document, $stages->first());

        if ($firstStageCandidates->count() !== 1) {
            return null;
        }

        $soloCandidate = $firstStageCandidates->first();

        $sameForEveryStage = $stages->every(function (WorkflowStage $stage) use ($document, $soloCandidate) {
            $stageCandidates = $this->eligibleApproversForStage($document, $stage);
            return $stageCandidates->count() === 1 && $stageCandidates->first()->user_id === $soloCandidate->user_id;
        });

        return $sameForEveryStage ? $soloCandidate : null;
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
     * time instead of racing across multiple approvers.
     *
     * Approvers marked busy/away are skipped in favor of an available
     * peer, unless every eligible approver is busy — an approver who
     * forgot to toggle back "available" should never permanently block a
     * document. Ties in workload are broken by user_id for determinism.
     */
    private function selectApproverForStage(DocumentRepository $document, WorkflowStage $stage): ?User
    {
        $candidates = $this->eligibleApproversForStage($document, $stage);

        if ($candidates->isEmpty()) {
            return null;
        }

        $available = $candidates->reject(fn (User $approver) => $approver->is_busy)->values();
        $pool = $available->isNotEmpty() ? $available : $candidates;

        $workloads = DocumentAssignment::whereIn('user_id', $pool->pluck('user_id'))
            ->where('individual_status', 'pending')
            ->selectRaw('user_id, count(*) as active_count')
            ->groupBy('user_id')
            ->pluck('active_count', 'user_id');

        $ranked = $pool->values()->all();
        usort($ranked, function (User $a, User $b) use ($workloads) {
            $countA = (int) ($workloads[$a->user_id] ?? 0);
            $countB = (int) ($workloads[$b->user_id] ?? 0);
            return $countA <=> $countB ?: $a->user_id <=> $b->user_id;
        });

        return $ranked[0] ?? null;
    }

    /**
     * The 25% Rule: allocate a quarter of the time remaining until the
     * document's absolute due_date as the approvers' SLA window for this
     * stage, floored at 2 hours (unless the due date itself doesn't allow
     * even that much, in which case the window is clamped to the due date).
     */
    private function computeApproverSlaExpiry(DocumentRepository $document): Carbon
    {
        $dueDate = Carbon::parse($document->due_date);
        $totalHoursLeft = now()->diffInHours($dueDate, false); // signed: negative if already overdue

        $approverSlaHours = (int) round($totalHoursLeft * self::APPROVER_SLA_FRACTION);

        if ($approverSlaHours < self::MIN_APPROVER_SLA_HOURS) {
            $approverSlaHours = self::MIN_APPROVER_SLA_HOURS;
        }

        $slaExpiresAt = now()->copy()->addHours($approverSlaHours);

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
     * single-assignment load balancing), unless $approver is explicitly
     * passed (used by the solo-approver shortcut, which already knows who
     * it's assigning to). A document is therefore only ever visible in one
     * approver's queue at a time for a given stage.
     */
    private function assignStage(DocumentRepository $document, WorkflowStage $stage, ?User $approver = null): void
    {
        $approver ??= $this->selectApproverForStage($document, $stage);

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
            // pending assignment across ALL stages, since with the
            // solo-approver shortcut more than one stage can be pending
            // simultaneously for the same approver.
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

        // Ensure the next configured stage has its single assignment. In
        // the just-in-time case it won't exist yet and is created here. In
        // the solo-approver case every stage was already pre-assigned in
        // routeToWorkflow() — skip re-creating it.
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