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
 * PARALLEL APPROVAL MODEL: when a document enters a stage, every eligible
 * approver for that stage is assigned simultaneously — no hierarchy, any
 * of them may act in any order. Whichever one decides first resolves the
 * stage; the other pending sibling assignments for that document+stage are
 * automatically closed to match (see completeStage()).
 *
 * ELIGIBILITY (stage-specific + load-balanced): an approver is eligible for
 * a stage if (a) their assigned_category matches the document, and
 * (b) either they have no specific stage restrictions at all (eligible for
 * every stage in their category by default) or this stage is explicitly
 * among their assigned stages (User::workflowStages()). Among eligible
 * approvers, those marked busy/away or already at the active-workload
 * threshold are skipped in favor of an available peer — unless skipping
 * would leave nobody eligible at all, in which case the full candidate
 * list is used anyway rather than leaving the document unassigned.
 *
 * SOLO-APPROVER SHORTCUT: if the exact same single approver is eligible for
 * every stage in the pipeline, all stages are assigned to them immediately
 * at upload time instead of one at a time — since the same person handles
 * every stage regardless of order, there's no reason to hide later stages.
 * Otherwise stages are entered one at a time, just-in-time, as each prior
 * stage resolves.
 *
 * Each parallel batch shares one sla_expires_at, computed as 25% of the
 * hours remaining until the document's own absolute due_date (min 2 hours,
 * never extending past the due_date itself).
 */
class WorkflowService
{
    /** Portion of the document's remaining time allotted to each stage's approvers. */
    private const APPROVER_SLA_FRACTION = 0.25;

    /** Floor for the approver SLA window, unless the due date doesn't allow it. */
    private const MIN_APPROVER_SLA_HOURS = 2;

    /** Load-balancing threshold (Feature: fallback logic for approvers). */
    private const MAX_ACTIVE_ASSIGNMENTS_PER_APPROVER = 5;

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
     */
    public function ingest(UploadedFile $file, User $originator, string $dueDate): DocumentRepository
    {
        return DB::transaction(function () use ($file, $originator, $dueDate) {
            $storedPath = $file->store('documents', 'local');

            $document = DocumentRepository::create([
                'originator_id' => $originator->user_id,
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
     * If the exact same single approver is eligible for every stage in the
     * pipeline, all stages are assigned to them immediately. Otherwise only
     * the FIRST stage is entered now; later stages are entered dynamically
     * as each prior one resolves (see completeStage()).
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

        $firstStageApprovers = $this->eligibleApproversForStage($document, $stages->first());

        $soloApproverHandlesEverything = $firstStageApprovers->count() === 1
            && $stages->every(function (WorkflowStage $stage) use ($document, $firstStageApprovers) {
                $stageApprovers = $this->eligibleApproversForStage($document, $stage);
                return $stageApprovers->count() === 1
                    && $stageApprovers->first()->user_id === $firstStageApprovers->first()->user_id;
            });

        if ($soloApproverHandlesEverything) {
            foreach ($stages as $stage) {
                $this->assignStageInParallel($document, $stage, $this->eligibleApproversForStage($document, $stage));
            }
        } else {
            $this->assignStageInParallel($document, $stages->first(), $firstStageApprovers);
        }
    }

    /**
     * Eligible approvers for a specific stage: matching category, and
     * either unrestricted (no specific stage picks) or explicitly assigned
     * to this stage — then load-balanced by availability/workload.
     */
    private function eligibleApproversForStage(DocumentRepository $document, WorkflowStage $stage): Collection
    {
        $candidates = User::where('role', 'approver')
            ->where('is_active', true)
            ->where('assigned_category', $document->ml_category)
            ->get()
            ->filter(function (User $approver) use ($stage) {
                $assignedStageIds = $approver->workflowStages()->pluck('workflow_stages.stage_id');
                // No explicit stage picks -> eligible for every stage in their category (default).
                return $assignedStageIds->isEmpty() || $assignedStageIds->contains($stage->stage_id);
            })
            ->values();

        if ($candidates->isEmpty()) {
            return $candidates;
        }

        // Load-balancing / fallback: prefer approvers who aren't marked
        // busy/away and are under the active-workload threshold. If that
        // would leave nobody, fall back to the full candidate list rather
        // than leaving the document completely unassigned.
        $available = $candidates->filter(function (User $approver) {
            if ($approver->is_busy) {
                return false;
            }
            $activeCount = DocumentAssignment::where('user_id', $approver->user_id)
                ->where('individual_status', 'pending')
                ->count();
            return $activeCount < self::MAX_ACTIVE_ASSIGNMENTS_PER_APPROVER;
        })->values();

        return $available->isNotEmpty() ? $available : $candidates;
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
     * Creates one DocumentAssignment per eligible approver for this stage,
     * all sharing the same computed sla_expires_at (parallel assignment).
     * Pass a pre-fetched $eligibleApprovers to avoid re-querying when the
     * caller already has it.
     */
    private function assignStageInParallel(DocumentRepository $document, WorkflowStage $stage, ?Collection $eligibleApprovers = null): void
    {
        $eligibleApprovers ??= $this->eligibleApproversForStage($document, $stage);

        if ($eligibleApprovers->isEmpty()) {
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

        foreach ($eligibleApprovers as $approver) {
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
                "New document assigned for '{$stage->stage_name}': {$document->title} (parallel review — any one of you may act).");
        }

        AuditLog::record(null, $document->document_id, 'route',
            "Stage '{$stage->stage_name}': assigned in parallel to {$eligibleApprovers->count()} approver(s) " .
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

    /** Process 5.0 — Approval Management. Approver decision on one parallel assignment. */
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
     * Resolves a stage once ANY one of its parallel assignments has been
     * decided (by an approver in decide(), by an Admin in
     * SlaService::adminOverride(), or automatically by
     * SlaService::autoApproveUnresolved()).
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
            // pending assignment across ALL stages (not just this stage's
            // parallel siblings), since with the solo-approver shortcut
            // multiple stages can be pending simultaneously.
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

        // Approved — close pending SIBLINGS for the SAME stage only
        // (parallel peers reviewing that one stage).
        $siblings = DocumentAssignment::where('document_id', $document->document_id)
            ->where('stage_id', $stage->stage_id)
            ->where('individual_status', 'pending')
            ->where('assignment_id', '!=', $assignment->assignment_id)
            ->get();

        foreach ($siblings as $sibling) {
            $sibling->individual_status = 'approved';
            $sibling->auto_approved = $auto;
            $sibling->comments = 'Auto-closed — stage already approved' . ($auto ? ' (system timeout)' : '') . '.';
            $sibling->acted_at = now();
            $sibling->save();
        }

        // Ensure the next configured stage has assignments. In the
        // just-in-time case they won't exist yet and are created here. In
        // the solo-approver case they were already pre-created in
        // routeToWorkflow() — skip re-creating them.
        $nextStage = WorkflowStage::where('document_category', $document->ml_category)
            ->where('sequence_order', '>', $stage->sequence_order)
            ->orderBy('sequence_order')
            ->first();

        if ($nextStage) {
            $alreadyAssigned = DocumentAssignment::where('document_id', $document->document_id)
                ->where('stage_id', $nextStage->stage_id)
                ->exists();

            if (!$alreadyAssigned) {
                $this->assignStageInParallel($document, $nextStage);
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