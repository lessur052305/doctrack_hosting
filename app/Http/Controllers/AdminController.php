<?php

namespace App\Http\Controllers;

use App\Events\AccountDeactivated;
use App\Events\DocumentStatusChanged;
use App\Mail\AutoApprovalDisputedMail;
use App\Models\AuditLog;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\MlModelRepository;
use App\Models\MlStagingSample;
use App\Models\NotificationRecord;
use App\Models\SlaHoliday;
use App\Models\SlaSetting;
use App\Models\SlaViolation;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\ClassificationService;
use App\Services\SlaService;
use App\Services\TextExtractionService;
use App\Services\ValidationService;
use App\Services\WorkflowService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function __construct(
        private ClassificationService $classifier,
        private TextExtractionService $extractor,
        private SlaService $sla,
        private WorkflowService $workflow,
    ) {
    }

    /**
     * The KPI stats + SLA alert list — shared by dashboard() (full page),
     * refresh() (the AJAX fragment the live-poll JS swaps in), and poll()
     * (which reuses the same cheap COUNT queries as its "did anything
     * change" signal, since they're already inexpensive).
     */
    private function overviewData(): array
    {
        $stats = [
            'total_documents' => DocumentRepository::count(),
            'pending' => DocumentRepository::whereIn('global_status', ['processing', 'classified_validated'])->count(),
            'approved' => DocumentRepository::whereIn('global_status', ['approved', 'auto_approved'])->count(),
            'rejected' => DocumentRepository::where('global_status', 'rejected')->count(),
            'active_users' => User::where('is_active', true)->count(),
        ];

        $slaAlerts = DocumentAssignment::where('escalated_to_admin', true)
            ->whereNull('admin_override_at')
            ->where('individual_status', 'pending')
            ->with(['document', 'stage'])
            ->orderBy('sla_expires_at')
            ->get();

        $reviewCount = DocumentAssignment::where('auto_approved', true)->whereNull('admin_reviewed_at')->count();

        return [$stats, $slaAlerts, $reviewCount];
    }

    /** Admin control-center overview. */
    public function dashboard()
    {
        [$stats, $slaAlerts, $reviewCount] = $this->overviewData();
        $activeModel = MlModelRepository::active();

        return view('admin.dashboard', compact('stats', 'slaAlerts', 'reviewCount', 'activeModel'));
    }

    /**
     * Renders the KPI cards + SLA alerts + Active ML Model fragment
     * (admin/partials/overview.blade.php) for the dashboard's live-poll JS
     * to swap in place — see resources/js/app.js's startLivePoll() and
     * dashboard.blade.php for why this beats a full page reload. The ML
     * Model panel is included here too, even though it rarely changes,
     * purely so the whole 3-column grid row (SLA alerts + ML model side
     * by side) stays one swap target instead of splitting the layout
     * across two independently-swapped pieces.
     */
    public function overviewRefresh()
    {
        [$stats, $slaAlerts, $reviewCount] = $this->overviewData();
        $activeModel = MlModelRepository::active();

        return view('admin.partials.overview', compact('stats', 'slaAlerts', 'reviewCount', 'activeModel'));
    }

    /**
     * Lightweight JSON endpoint the dashboard's JS polls every ~5-10s.
     * Reuses the same COUNT queries overviewData() already runs — they're
     * cheap enough that there's no separate "cheaper" signal worth
     * computing just for the poll.
     */
    public function overviewPoll()
    {
        [$stats, $slaAlerts, $reviewCount] = $this->overviewData();

        return response()->json(['stats' => $stats, 'sla_alert_count' => $slaAlerts->count(), 'review_count' => $reviewCount]);
    }

    // ---------------------------------------------------------------
    // User account management (Section 3: Account ID <-> workflow role)
    // ---------------------------------------------------------------

    public function users(Request $request)
    {
        $stagesByCategory = WorkflowStage::where('is_archived', false)->orderBy('sequence_order')->get()->groupBy('document_category');

        return view('admin.users', array_merge(
            compact('stagesByCategory'),
            $this->usersTableData($request)
        ));
    }

    /**
     * Fragment refresh for the account list — same live-channel/poll
     * pattern used elsewhere (see ml_training.blade.php's #ml-review-panels).
     * Verification status doesn't broadcast via DocumentRepository::
     * booted()-style model hooks (there's no document involved at all),
     * so without this an admin watching this page would only see the
     * "Unverified" badge disappear on their next manual reload — see
     * AuthController::verifyEmail() firing UserVerified.
     */
    public function usersRefresh(Request $request)
    {
        return view('admin.partials.users_table', $this->usersTableData($request));
    }

    /** Lightweight JSON signal for the poll fallback — see overviewPoll()'s docblock for the same reasoning. */
    public function usersPoll()
    {
        return response()->json([
            'unverified_ids' => User::whereNull('email_verified_at')->pluck('user_id'),
        ]);
    }

    /** @return array{users: \Illuminate\Contracts\Pagination\LengthAwarePaginator, showInactive: bool, inactiveCount: int} */
    private function usersTableData(Request $request): array
    {
        $showInactive = $request->boolean('show_inactive');

        $query = User::with(['createdBy', 'workflowStages'])->orderBy('role');
        if (!$showInactive) {
            $query->where('is_active', true);
        }

        return [
            'users' => $query->paginate(15)->withQueryString(),
            'showInactive' => $showInactive,
            'inactiveCount' => User::where('is_active', false)->count(),
        ];
    }

    public function storeUser(Request $request)
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:50', 'unique:users,username'],
            'full_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:100', 'unique:users,email'],
            'role' => ['required', 'in:admin,originator,approver'],
            'assigned_category' => [
                'nullable',
                'required_if:role,approver',
                'in:' . implode(',', ValidationService::knownCategories()),
            ],
            'stage_ids' => ['nullable', 'array'],
            'stage_ids.*' => ['integer', 'exists:workflow_stages,stage_id'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create([
            'username' => $validated['username'],
            'full_name' => $validated['full_name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            // Only Approvers are ever scoped to a category. Admin and
            // Originator accounts always get null here regardless of what
            // was submitted — Originators upload any document type and are
            // classified automatically, so they are never restricted.
            'assigned_category' => $validated['role'] === 'approver' ? $validated['assigned_category'] : null,
            'password_hash' => Hash::make($validated['password']),
            'created_by' => $request->user()->user_id,
            'is_active' => true,
        ]);

        if ($user->role === 'approver' && !empty($validated['stage_ids'])) {
            // Server-side integrity check: only sync stage IDs that actually
            // belong to this approver's chosen category.
            $validStageIds = WorkflowStage::where('document_category', $user->assigned_category)
                ->whereIn('stage_id', $validated['stage_ids'])
                ->pluck('stage_id');
            $user->workflowStages()->sync($validStageIds);
        }

        AuditLog::record($request->user()->user_id, null, 'user_create',
            "Created account #{$user->user_id} ({$user->username}) with role '{$user->role}'" .
            ($user->assigned_category ? ", assigned category '{$user->assigned_category}'." : '.'));

        // Login is blocked until this is clicked (see AuthController::
        // login()) — sent immediately so the account is usable as soon as
        // its owner checks their inbox, not left silently unusable.
        $user->sendEmailVerificationNotification();

        return back()->with('status', "Account '{$user->username}' created. A verification email was sent to {$user->email}.");
    }

    /**
     * Re-sends the verification email — the only way an unverified account
     * gets a second chance at the link, since the account holder can't log
     * in yet to request it themselves (see AuthController::login()).
     */
    public function resendVerification(User $user)
    {
        abort_if($user->hasVerifiedEmail(), 409, 'This account is already verified.');

        $user->sendEmailVerificationNotification();

        return back()->with('status', "Verification email re-sent to {$user->email}.");
    }

    /** Admin-only: view/edit which specific stages an approver is restricted to. */
    public function editApproverStages(User $user)
    {
        abort_unless($user->role === 'approver', 422, 'Only approver accounts have stage assignments.');

        $stagesByCategory = WorkflowStage::where('is_archived', false)->orderBy('sequence_order')->get()->groupBy('document_category');
        $assignedStageIds = $user->workflowStages()->pluck('workflow_stages.stage_id')->all();

        // Informational only — reassigning category/stages never touches
        // already-created DocumentAssignment rows (their approver_id and
        // sla_expires_at are fixed at routing time and never re-evaluated),
        // so this doesn't block the change. It just tells the admin what's
        // still sitting in this approver's queue before they decide.
        $pendingInOldCategory = DocumentAssignment::pendingFor($user->user_id)->count();

        return view('admin.approver_stages', compact('user', 'stagesByCategory', 'assignedStageIds', 'pendingInOldCategory'));
    }

    /**
     * Updates an approver's category and/or which specific stages within it
     * they handle (Feature: Dynamic Workflow Assignment). Changing category
     * always resets stage picks to "every stage in the new category"
     * (unrestricted) rather than silently carrying over stage_ids that
     * belonged to the old category and would be meaningless in the new one.
     * Leaving every checkbox unchecked has the same "unrestricted" effect.
     *
     * Already-created DocumentAssignment rows are untouched by this — see
     * WorkflowService::eligibleApproversForStage(), which only consults
     * assigned_category/workflowStages() when routing a NEW document. A
     * pending assignment this approver already holds stays in their queue
     * and can still be decided normally regardless of this change.
     */
    public function updateApproverStages(Request $request, User $user)
    {
        abort_unless($user->role === 'approver', 422, 'Only approver accounts have stage assignments.');

        $validated = $request->validate([
            'assigned_category' => ['required', 'in:' . implode(',', ValidationService::knownCategories())],
            'stage_ids' => ['nullable', 'array'],
            'stage_ids.*' => ['integer', 'exists:workflow_stages,stage_id'],
        ]);

        $categoryChanged = $validated['assigned_category'] !== $user->assigned_category;
        $oldCategory = $user->assigned_category;

        // Re-validated server-side against whichever category was actually
        // submitted — the category dropdown and stage checkboxes are only
        // kept in sync client-side, so a tampered request could otherwise
        // submit stage IDs from a different category entirely.
        $validStageIds = WorkflowStage::where('document_category', $validated['assigned_category'])
            ->whereIn('stage_id', $validated['stage_ids'] ?? [])
            ->pluck('stage_id');

        $user->assigned_category = $validated['assigned_category'];
        $user->save();

        // A category switch always clears stage picks (see docblock above);
        // otherwise sync whatever was actually submitted for this category.
        $user->workflowStages()->sync($categoryChanged ? [] : $validStageIds);

        $description = $categoryChanged
            ? "Reassigned {$user->full_name} (#{$user->user_id}) from '{$oldCategory}' to '{$validated['assigned_category']}'. Stage assignments reset to unrestricted (all stages in the new category)."
            : "Updated stage assignments for {$user->full_name} (#{$user->user_id}): " .
                ($validStageIds->isEmpty() ? 'all stages in category (no restriction).' : implode(', ', $validStageIds->all()));

        AuditLog::record($request->user()->user_id, null, 'assign_stages', $description);

        return redirect()->route('admin.users')->with('status', "Stage assignments updated for {$user->full_name}.");
    }

    /**
     * Deactivation handoff (Feature): deactivating an approver who's
     * holding pending work automatically hands each assignment to the same
     * least-busy-eligible-approver a brand-new document would get routed
     * to (see WorkflowService::findReplacementApprover()), or escalates it
     * straight to Admin if nobody's eligible (see SlaService::
     * escalateForReassignmentFailure()) — rather than leaving it stuck in a
     * queue nobody can reach anymore. is_active is flipped BEFORE the
     * reassignment loop runs, not after — otherwise the approver being
     * deactivated could still show up as their own eligible replacement.
     */
    public function toggleUser(Request $request, User $user)
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $reason = $validated['reason'] ?? null;
        $wasActive = $user->is_active;

        $user->is_active = !$wasActive;
        $user->save();

        // Push this the instant it happens, not just via the notification
        // bell — a deactivated user sitting idle on a page should be logged
        // out immediately rather than only finding out on their next click
        // (see the 'account.deactivated' listener in app.js).
        if ($wasActive && !$user->is_active) {
            event(new AccountDeactivated($user->user_id));
        }

        $reassignedCount = 0;
        $escalatedCount = 0;

        if ($wasActive && $user->role === 'approver') {
            $pendingAssignments = DocumentAssignment::where('user_id', $user->user_id)
                ->where('individual_status', 'pending')
                ->where('escalated_to_admin', false)
                ->with(['document', 'stage'])
                ->get();

            foreach ($pendingAssignments as $assignment) {
                $replacement = $this->workflow->findReplacementApprover($assignment);

                if ($replacement) {
                    $this->workflow->reassignAssignment($assignment, $replacement, $user, $reason);
                    $reassignedCount++;
                } else {
                    $this->sla->escalateForReassignmentFailure($assignment);
                    $escalatedCount++;
                }
            }
        }

        AuditLog::record($request->user()->user_id, null, 'user_toggle',
            "Account #{$user->user_id} ({$user->username}) set to " . ($user->is_active ? 'active' : 'inactive') . '.' .
            ($reason ? " Reason: \"{$reason}\"" : '') .
            ($reassignedCount > 0 ? " {$reassignedCount} pending assignment(s) reassigned." : '') .
            ($escalatedCount > 0 ? " {$escalatedCount} escalated to Admin (no eligible replacement)." : ''));

        $status = 'Account status updated.';
        if ($reassignedCount > 0 || $escalatedCount > 0) {
            $status .= " {$reassignedCount} assignment(s) reassigned, {$escalatedCount} escalated to Admin.";
        }

        return back()->with('status', $status);
    }

    // ---------------------------------------------------------------
    // ML dataset training (5–10 sample uploads per category — Scope 1.4)
    // ---------------------------------------------------------------

    private const TRAINING_MIN_PER_CATEGORY = 5;
    // Deliberately no lifetime-total ceiling per category — the corpus is
    // meant to keep growing forever as an admin confirms more documents
    // from the ML Review queue over the system's lifetime (see
    // trainModel()'s trained_in_model_id stamping below for how "already
    // taught the model something" is tracked instead of ever deleting a
    // sample). This is purely a per-REQUEST batch limit — the original
    // reason staging is split by category at all (see stageTrainingSamples()'s
    // docblock) — not a total-staged cap.
    private const TRAINING_BATCH_UPLOAD_LIMIT = 20;
    // Above this word-overlap fraction, a newly staged sample is flagged as
    // a likely near-duplicate of one already staged in the same category
    // (see stageTrainingSamples()). Chosen with headroom above what
    // genuinely different same-category documents naturally share — real,
    // distinct business documents in one category (different department,
    // item, dates) were observed sharing up to ~80% of their vocabulary
    // just from required boilerplate + domain terms; 0.85 flags true
    // near-copies without punishing legitimate variety.
    private const NEAR_DUPLICATE_THRESHOLD = 0.85;
    // Deliberately much stricter than NEAR_DUPLICATE_THRESHOLD above — that
    // one is tuned to be LOOSE (catch near-copies while still letting
    // genuinely different same-category documents through, since those
    // can legitimately share up to ~80% vocabulary). This one decides
    // whether one review decision is allowed to resolve multiple pending
    // documents together (see reviewFlaggedDocument()) — a much higher bar
    // is needed there, since a false-positive match at this stage would
    // silently confirm-and-route (or reject) a document the admin never
    // actually looked at. Not 100%: OCR isn't perfectly deterministic even
    // across two scans/formats of the literal same real document.
    private const EXACT_DUPLICATE_THRESHOLD = 0.97;

    public function mlTraining()
    {
        $categories = ValidationService::knownCategories();
        $activeModel = MlModelRepository::active();
        $history = MlModelRepository::orderByDesc('last_trained')->limit(10)->get();

        // Shared across every admin, not scoped to the current session —
        // deliberately so: this app only ever has one active classifier at
        // a time, so there's nothing "personal" about staged samples for
        // it. Storing them in the session tied them to one browser/login
        // and silently lost progress on logout, session expiry, or
        // switching devices; any admin can now pick up where another left
        // off. See the ml_staging_samples migration.
        $stagedSamples = MlStagingSample::with(['stagedBy', 'trainedInModel'])->orderBy('created_at')->get()->groupBy('category');
        $minPerCategory = self::TRAINING_MIN_PER_CATEGORY;
        $batchUploadLimit = self::TRAINING_BATCH_UPLOAD_LIMIT;

        return view('admin.ml_training', array_merge(compact(
            'categories', 'activeModel', 'history', 'stagedSamples', 'minPerCategory', 'batchUploadLimit'
        ), $this->mlReviewQueueData()));
    }

    /**
     * Fragment refresh for the Awaiting ML Review / Confirmed From Review
     * panels — same live-channel/poll pattern already used elsewhere (e.g.
     * ArchiveController::refresh(), AdminController::violationsRefresh()).
     * A new low-confidence upload doesn't reach this page via any normal
     * status change on an EXISTING row (see the manual event() calls in
     * WorkflowService::process()/reviewFlaggedDocument() — DocumentRepository
     * ::booted() only fires on an update, never a create), so without this
     * an admin sitting on this page would only see a newly-held document
     * after manually reloading.
     */
    public function mlReviewQueueRefresh()
    {
        return view('admin.partials.ml_review_panels', array_merge(
            $this->mlReviewQueueData(),
            ['categories' => ValidationService::knownCategories()]
        ));
    }

    /** Lightweight JSON signal for the poll fallback — see overviewPoll()'s docblock for the same reasoning. */
    public function mlReviewQueuePoll()
    {
        $data = $this->mlReviewQueueData();

        // Includes grouped-away "similar" document ids too, not just each
        // group's primary — a new upload that gets absorbed into an
        // EXISTING group wouldn't otherwise change this signal at all
        // (the primary ids stay the same), silently missing a live refresh.
        $pendingIds = $data['reviewQueue']
            ->flatMap(fn ($entry) => [
                $entry->document->document_id,
                ...$entry->similar->pluck('document_id'),
                ...$entry->exactDuplicates->pluck('document_id'),
            ])
            ->all();

        return response()->json([
            'pending_ids' => $pendingIds,
            'confirmed_ids' => $data['stagedFromReview']->pluck('document_id')->all(),
        ]);
    }

    /** @return array{reviewQueue: \Illuminate\Support\Collection, priorityThreshold: int, stagedFromReview: \Illuminate\Support\Collection} */
    private function mlReviewQueueData(): array
    {
        $priorityThreshold = config('ml.review_priority_threshold', 30);

        return [
            'reviewQueue' => $this->buildReviewQueueGroups($priorityThreshold),
            'priorityThreshold' => $priorityThreshold,
            // Documents already confirmed + routed from the review queue,
            // so an admin can "Re-check" them once the model has been
            // retrained on a sample they contributed — see recheckFlaggedDocument().
            // Excludes ones dismissed via the "x" button (see
            // dismissRecheckedDocument()) — a pure UI hide, not a data change.
            'stagedFromReview' => DocumentRepository::where('ml_review_status', 'confirmed')
                ->whereNull('ml_recheck_dismissed_at')
                ->orderByDesc('updated_at')
                ->limit(20)
                ->get(),
            // Compared against each row's confirmed_at_model_id so the
            // view only offers "Re-check" once this has actually changed
            // since confirmation — see recheckFlaggedDocument()'s gate.
            'activeModelId' => MlModelRepository::active()?->model_id,
        ];
    }

    /**
     * Groups pending-review documents so near-identical uploads (the same
     * template submitted by different people) collapse into one row
     * instead of forcing the admin to review five copies of the same
     * thing. Reuses the exact word-overlap check already used to warn
     * about near-duplicate training samples (see stageTrainingSamples()) —
     * same threshold, same reasoning: real distinct documents naturally
     * share a lot of boilerplate, so this only catches true near-copies.
     *
     * @return \Illuminate\Support\Collection<int, object{document: DocumentRepository, similarCount: int}>
     */
    private function buildReviewQueueGroups(int $priorityThreshold): \Illuminate\Support\Collection
    {
        $pending = DocumentRepository::where('ml_review_status', 'pending')
            ->orderBy('ml_confidence')
            ->with('originator')
            ->get();

        $absorbed = [];
        $groups = collect();

        foreach ($pending as $doc) {
            if (in_array($doc->document_id, $absorbed, true)) {
                continue;
            }

            // Two different buckets for two different reasons:
            //  - 'similar' (>= NEAR_DUPLICATE_THRESHOLD, < EXACT_DUPLICATE_THRESHOLD):
            //    grouped purely for display, but still needs its OWN
            //    reachable Confirm/Reject (see ml_review_panels.blade.php's
            //    expandable list) — confirming/rejecting the primary has no
            //    effect on these, and a document with no action of its own
            //    would sit at ml_review_status='pending' forever.
            //  - 'exactDuplicates' (>= EXACT_DUPLICATE_THRESHOLD): genuinely
            //    the same document — reviewFlaggedDocument() resolves these
            //    together with the primary in one decision, so they're
            //    listed here only as a heads-up of what that click will
            //    also affect, not as separately-actionable rows.
            $similar = collect();
            $exactDuplicates = collect();
            foreach ($pending as $other) {
                if ($other->document_id === $doc->document_id || in_array($other->document_id, $absorbed, true)) {
                    continue;
                }
                $similarity = $this->classifier->wordOverlapSimilarity((string) $doc->ocr_text, (string) $other->ocr_text);
                if ($similarity >= self::EXACT_DUPLICATE_THRESHOLD) {
                    $absorbed[] = $other->document_id;
                    $exactDuplicates->push($other);
                } elseif ($similarity >= self::NEAR_DUPLICATE_THRESHOLD) {
                    $absorbed[] = $other->document_id;
                    $similar->push($other);
                }
            }

            $groups->push((object) [
                'document' => $doc,
                'similar' => $similar,
                'exactDuplicates' => $exactDuplicates,
                'isPriority' => (float) $doc->ml_confidence < $priorityThreshold,
            ]);
        }

        return $groups;
    }

    /**
     * Admin confirms (optionally correcting) or rejects a low-confidence
     * document held by WorkflowService::process() — held, not just flagged,
     * because a wrong SVM guess that happens to pass its (wrong) category's
     * validation would otherwise reach the wrong approvers with no clean
     * way to undo an approval after the fact (see process()'s docblock).
     *
     * 'confirm' is the only path that actually routes the document into
     * the workflow (WorkflowService::routeToWorkflow()) — it deliberately
     * requires the admin's own category choice rather than trusting the
     * SVM's uncertain guess as ground truth, since an unconfirmed
     * low-confidence label is exactly the case where that guess is least
     * trustworthy. Also always stages it into the same MlStagingSample pool
     * trainModel() trains from — an admin confirming a category IS the
     * confirmation that it's a good example to learn from, so there's no
     * separate opt-in.
     *
     * 'reject' means the admin could not confirm ANY category fits (bad
     * scan, garbage upload, genuinely ambiguous document) — sets
     * global_status to 'rejected', which is deliberately the same terminal
     * state a rejected-by-approver document reaches, so it reuses the
     * originator's existing resubmit flow rather than needing a new one.
     */
    public function reviewFlaggedDocument(Request $request, DocumentRepository $document)
    {
        abort_unless($document->ml_review_status === 'pending', 404);

        $validated = $request->validate([
            'action' => ['required', 'in:confirm,reject'],
            'category' => ['required_if:action,confirm', 'nullable', Rule::in(ValidationService::knownCategories())],
        ]);

        $admin = $request->user();

        // Genuinely-identical siblings still pending review — one decision
        // resolves all of them together, since repeating the same call for
        // what is functionally the same document is pure busywork. Each
        // one is still routed through the workflow individually (its own
        // assignment, SLA window, audit trail, notification) — only the
        // manual review step merges, not the actual processing.
        $duplicates = $this->findExactDuplicateSiblings($document);

        if ($validated['action'] === 'reject') {
            $this->rejectReviewedDocument($document, $admin);
            foreach ($duplicates as $duplicate) {
                $this->rejectReviewedDocument($duplicate, $admin);
            }

            $status = "Rejected '{$document->title}'"
                . ($duplicates->isNotEmpty() ? " and {$duplicates->count()} identical document(s) along with it" : '')
                . ' — the originator(s) have been notified to resubmit.';

            return back()->with('status', $status);
        }

        $category = $validated['category'];

        // Only the primary gets staged as a training sample — staging
        // every identical copy too would just trip the near-duplicate
        // warning below against itself, for no benefit to the corpus.
        $duplicateWarning = $this->confirmReviewedDocument($document, $category, $admin, stageForTraining: true);
        foreach ($duplicates as $duplicate) {
            $this->confirmReviewedDocument($duplicate, $category, $admin, stageForTraining: false);
        }

        $status = "Confirmed '{$document->title}' as '{$category}'"
            . ($duplicates->isNotEmpty() ? " and routed {$duplicates->count()} identical document(s) along with it" : '')
            . '.';
        $response = back()->with('status', $status);

        return $duplicateWarning ? $response->with('warning', [$duplicateWarning]) : $response;
    }

    /**
     * Other still-pending documents whose text is a near-exact match of
     * this one (see EXACT_DUPLICATE_THRESHOLD's docblock for why this is a
     * much stricter bar than the display-grouping threshold). Used to let
     * one review decision resolve a whole batch of identical uploads at
     * once — deliberately recomputed fresh from the DB at review time
     * rather than trusting any client-supplied list of ids, since an admin
     * should only ever be able to bulk-resolve documents actually verified
     * server-side to be duplicates of the one they're looking at.
     */
    private function findExactDuplicateSiblings(DocumentRepository $document): \Illuminate\Support\Collection
    {
        return DocumentRepository::where('ml_review_status', 'pending')
            ->where('document_id', '!=', $document->document_id)
            ->get()
            ->filter(fn (DocumentRepository $other) => $this->classifier->wordOverlapSimilarity(
                (string) $document->ocr_text,
                (string) $other->ocr_text
            ) >= self::EXACT_DUPLICATE_THRESHOLD)
            ->values();
    }

    private function rejectReviewedDocument(DocumentRepository $document, User $admin): void
    {
        $document->ml_review_status = 'dismissed';
        $document->global_status = 'rejected';
        $document->save();

        AuditLog::record($admin->user_id, $document->document_id, 'ml_review_reject',
            "Rejected '{$document->title}' during ML review — no category could be confidently confirmed " .
            "(originally classified as '{$document->ml_category}' at {$document->ml_confidence}%). Not routed for approval.");

        NotificationRecord::send($document->originator_id, $document->document_id,
            "Your document '{$document->title}' could not be confidently classified by an admin and was not routed for approval. " .
            'Please review it and resubmit a corrected version.');
    }

    /** @return string|null A near-duplicate-in-training-staging warning, only when $stageForTraining. */
    private function confirmReviewedDocument(DocumentRepository $document, string $category, User $admin, bool $stageForTraining): ?string
    {
        $duplicateWarning = null;

        if ($stageForTraining) {
            foreach (MlStagingSample::where('category', $category)->get(['original_filename', 'extracted_text']) as $existing) {
                $similarity = $this->classifier->wordOverlapSimilarity((string) $document->ocr_text, $existing->extracted_text);
                if ($similarity >= self::NEAR_DUPLICATE_THRESHOLD) {
                    $duplicateWarning = sprintf(
                        '"%s" looks like a near-duplicate of already-staged "%s" (%d%% word overlap) — staged anyway, but consider whether a more varied example would help more.',
                        $document->title,
                        $existing->original_filename,
                        round($similarity * 100)
                    );
                    break;
                }
            }

            MlStagingSample::create([
                'category' => $category,
                'original_filename' => $document->original_filename ?? $document->title,
                'extracted_text' => (string) $document->ocr_text,
                'staged_by' => $admin->user_id,
            ]);
        }

        $originalCategory = $document->ml_category;
        $originalConfidence = $document->ml_confidence;

        $document->ml_category = $category;
        $document->ml_review_status = 'confirmed';
        // Snapshot of what's active right now — see recheckFlaggedDocument()'s
        // gate: "Re-check" only becomes meaningful once the active model
        // has actually changed since this moment.
        $document->confirmed_at_model_id = MlModelRepository::active()?->model_id;
        $document->save();

        // Only now — the whole point of holding it — does this document
        // actually reach any approver's dashboard.
        $this->workflow->routeToWorkflow($document);

        // ml_review_status changing isn't global_status/disputed_at, so
        // DocumentRepository::booted() won't broadcast this on its own —
        // fire it manually so this document drops off every OTHER admin's
        // review queue live too, not just the acting admin's (who already
        // sees it via this request's own page reload).
        event(new DocumentStatusChanged($document));

        AuditLog::record($admin->user_id, $document->document_id, 'ml_review_confirm',
            "Confirmed '{$document->title}' as '{$category}' (originally classified as '{$originalCategory}' at {$originalConfidence}%) " .
            'and routed it for approval.' . ($stageForTraining ? ' Added to training staging.' : ' Identical to another document already staged for training in this batch.'));

        NotificationRecord::send($document->originator_id, $document->document_id,
            "Your document '{$document->title}' was confirmed as '{$category}' by an admin and has been routed for approval.");

        return $duplicateWarning;
    }

    /**
     * Re-runs classification for a document already confirmed by the
     * review queue, against whichever model is active right now —
     * deliberately writes to ml_recheck_* rather than overwriting
     * ml_category/ml_confidence, since those already drove this document's
     * real workflow routing and shouldn't be silently rewritten after the
     * fact. Lets an admin see, concretely, whether retraining on their
     * correction actually improved how this document would score.
     *
     * Gated on the active model having actually changed since this
     * document was confirmed (see confirmed_at_model_id, set in
     * confirmReviewedDocument()) — without this, re-checking before any
     * retrain just re-classifies against the exact same model, producing a
     * meaningless no-op result (identical before/after) that still shows
     * up on the originator's tracking page looking like something happened.
     */
    public function recheckFlaggedDocument(Request $request, DocumentRepository $document)
    {
        abort_unless($document->ml_review_status === 'confirmed', 404);

        $activeModelId = MlModelRepository::active()?->model_id;
        abort_unless($activeModelId !== null && $activeModelId !== $document->confirmed_at_model_id, 409,
            'The model has not been retrained since this document was confirmed — nothing new to check yet.');

        $result = $this->classifier->classify((string) $document->ocr_text);

        $document->ml_recheck_category = $result['category'];
        $document->ml_recheck_confidence = $result['confidence'];
        $document->ml_rechecked_at = now();
        $document->save();

        // ml_recheck_* changing isn't global_status/disputed_at, so
        // DocumentRepository::booted() won't broadcast this on its own —
        // fire it manually, same reasoning as the confirm action, so every
        // admin watching this page sees the new result live, not just
        // whoever clicked "Re-check."
        event(new DocumentStatusChanged($document));

        AuditLog::record($request->user()->user_id, $document->document_id, 'ml_recheck',
            "Re-checked '{$document->title}' against the current model: '{$result['category']}' at {$result['confidence']}% " .
            "(originally '{$document->ml_category}' at {$document->ml_confidence}%).");

        return back()->with('status', "Re-check: '{$result['category']}' at {$result['confidence']}% confidence.");
    }

    /**
     * Dismisses a row from the "Confirmed From Review" panel once an admin
     * has re-checked it and is done watching — a pure UI flag. Deliberately
     * only allowed after a re-check has actually happened (ml_rechecked_at
     * set): dismissing something before ever re-checking it wouldn't fit
     * the intended stage → retrain → re-check → done flow, and the "x"
     * button itself is only rendered once ml_rechecked_at is set (see
     * ml_review_panels.blade.php) — this mirrors that same guard
     * server-side rather than trusting the UI alone.
     */
    public function dismissRecheckedDocument(Request $request, DocumentRepository $document)
    {
        abort_unless($document->ml_review_status === 'confirmed' && $document->ml_rechecked_at !== null, 404);

        $document->ml_recheck_dismissed_at = now();
        $document->save();

        event(new DocumentStatusChanged($document));

        return back()->with('status', "Dismissed '{$document->title}' from the re-check list.");
    }

    /**
     * Uploads and text-extracts sample documents for ONE category at a
     * time, accumulating them in a shared table rather than requiring
     * every category's files in a single request. A single combined
     * submission (up to 30 files across 3 categories) can silently exceed
     * PHP's max_file_uploads ini limit (default 20) — files past that
     * cutoff are dropped by PHP itself before Laravel ever sees them, with
     * no error pointing at the real cause. max_file_uploads is
     * PHP_INI_SYSTEM only (no .htaccess/.user.ini/runtime override exists
     * for it), so fixing this by raising the limit isn't an option without
     * root on every future deployment — staging per category (well under
     * any reasonable limit) sidesteps the ceiling entirely instead of
     * depending on it.
     */
    public function stageTrainingSamples(Request $request, string $category)
    {
        abort_unless(in_array($category, ValidationService::knownCategories(), true), 404);

        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:' . self::TRAINING_BATCH_UPLOAD_LIMIT],
            'files.*' => ['required', 'file', 'mimes:pdf,txt,docx', 'max:10240'],
        ]);

        // Compared against as each new file is staged, growing to include
        // files from THIS same batch too — so uploading two near-identical
        // files in one request catches the second against the first, not
        // just against whatever was already staged before this request.
        $existingSamples = MlStagingSample::where('category', $category)->get(['original_filename', 'extracted_text']);
        $duplicateWarnings = [];

        foreach ($validated['files'] as $file) {
            $text = $this->extractor->extract($file)['text'];

            foreach ($existingSamples as $existing) {
                $similarity = $this->classifier->wordOverlapSimilarity($text, $existing->extracted_text);
                if ($similarity >= self::NEAR_DUPLICATE_THRESHOLD) {
                    $duplicateWarnings[] = sprintf(
                        '"%s" looks like a near-duplicate of already-staged "%s" (%d%% word overlap) — consider a more varied real example instead.',
                        $file->getClientOriginalName(),
                        $existing->original_filename,
                        round($similarity * 100)
                    );
                    break; // one warning per new file is enough, no need to list every match
                }
            }

            $existingSamples->push(MlStagingSample::create([
                'category' => $category,
                'original_filename' => $file->getClientOriginalName(),
                'extracted_text' => $text,
                'staged_by' => $request->user()->user_id,
            ]));
        }

        $totalStaged = MlStagingSample::where('category', $category)->count();

        $response = back()->with('status', count($validated['files']) . " sample(s) added for '{$category}' ({$totalStaged} total staged).");

        if ($duplicateWarnings) {
            $response->with('warning', $duplicateWarnings);
        }

        return $response;
    }

    public function clearTrainingStaging(Request $request, string $category)
    {
        abort_unless(in_array($category, ValidationService::knownCategories(), true), 404);

        MlStagingSample::where('category', $category)->delete();

        return back()->with('status', "Cleared staged samples for '{$category}'.");
    }

    /** Removes one staged sample without clearing the rest of its category. */
    public function destroyTrainingSample(Request $request, MlStagingSample $sample)
    {
        $sample->delete();

        return back()->with('status', "Removed '{$sample->original_filename}' from staging.");
    }

    public function trainModel(Request $request)
    {
        $categories = ValidationService::knownCategories();
        $stagedSamples = MlStagingSample::orderBy('category')->get()->groupBy('category');

        foreach ($categories as $category) {
            $count = $stagedSamples->get($category, collect())->count();
            abort_if($count < self::TRAINING_MIN_PER_CATEGORY, 422,
                "'{$category}' needs at least " . self::TRAINING_MIN_PER_CATEGORY . " staged samples (has {$count}).");
        }

        $samplesByCategory = $stagedSamples->map(fn ($samples) => $samples->pluck('extracted_text')->all())->all();

        $model = $this->classifier->train($samplesByCategory);

        AuditLog::record($request->user()->user_id, null, 'ml_train',
            "Trained model #{$model->model_id} ({$model->version}) on {$model->training_sample_count} samples across " . count($categories) . ' categories. Estimated accuracy: ' . $model->accuracy_score . '%.');

        // Staged samples deliberately survive training now (no more
        // truncate() here) — an admin can keep adding samples across
        // multiple sessions and have the NEXT training run combine
        // everything staged so far into one larger corpus, rather than
        // every run starting from zero again. Use "Clear" on the ML
        // Training page to explicitly wipe a category's staging if a fresh
        // start is ever actually wanted.
        //
        // Every row gets swept into $samplesByCategory above regardless of
        // category (no per-category filtering happens before train()), so
        // stamping every currently-staged row here is accurate, not an
        // approximation — lets the page show "already taught this model
        // something" vs "still waiting for the next retrain" per sample.
        MlStagingSample::query()->update(['trained_in_model_id' => $model->model_id]);

        return back()->with('status', "Model {$model->version} trained successfully on {$model->training_sample_count} samples (est. accuracy {$model->accuracy_score}%). Staged samples are kept — add more anytime and retrain to combine them.");
    }

    // ---------------------------------------------------------------
    // SLA override queue (Section 5)
    // ---------------------------------------------------------------

    /**
     * SLA Override Queue. Breached assignments are nested the same way as
     * the Approver dashboard: documents an Originator uploaded together in
     * one SubmissionBatch stay grouped under one container so Admins can
     * see at a glance which breach belongs to which original request,
     * rather than a flat list of unrelated-looking rows.
     */
    public function slaQueue(Request $request)
    {
        $breached = DocumentAssignment::where('escalated_to_admin', true)
            ->whereNull('admin_override_at')
            ->where('individual_status', 'pending')
            ->with(['document.batch', 'document.originator', 'document.assignments.approver', 'stage', 'approver'])
            ->orderBy('sla_expires_at')
            ->get();

        $containers = $breached
            ->groupBy(fn (DocumentAssignment $a) => $a->document->batch_id ? 'batch-' . $a->document->batch_id : 'doc-' . $a->document_id)
            ->map(function ($groupAssignments) {
                $first = $groupAssignments->first();
                $batch = $first->document->batch;

                return (object) [
                    'is_batch' => (bool) $batch,
                    'batch' => $batch,
                    'due_date' => $batch->due_date ?? $first->document->due_date,
                    'originator' => $first->document->originator,
                    'documents' => $groupAssignments->groupBy('document_id'),
                ];
            })
            ->sortBy(fn ($c) => $c->due_date)
            ->values();

        $perPage = 10;
        $page = (int) $request->input('page', 1);

        $assignments = new \Illuminate\Pagination\LengthAwarePaginator(
            $containers->forPage($page, $perPage)->values(),
            $containers->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        // Grouped by document, same reasoning as $containers above: a
        // document can have MORE than one auto-approved stage awaiting
        // review at once (e.g. Budget Check and Final Approval both fired),
        // and a flat per-stage list made that look like unrelated rows.
        $reviewAssignments = DocumentAssignment::where('auto_approved', true)
            ->whereNull('admin_reviewed_at')
            ->with(['document', 'stage', 'approver'])
            ->get();

        $reviewContainers = $reviewAssignments
            ->groupBy('document_id')
            ->map(fn ($stageAssignments) => (object) [
                'document' => $stageAssignments->first()->document,
                'assignments' => $stageAssignments->sortBy(fn ($a) => $a->stage->sequence_order)->values(),
            ])
            ->sortBy(fn ($c) => $c->assignments->first()->acted_at)
            ->values();

        return view('admin.sla_queue', compact('assignments', 'reviewContainers'));
    }

    /**
     * Section 5 follow-up: review every stage the SYSTEM auto-approved on
     * ONE document, all at once — an admin reviews the document as a
     * whole, not stage-by-stage (a document can have more than one
     * auto-approved stage awaiting review, e.g. Budget Check AND Final
     * Approval both firing). Confirming just leaves a note on each.
     * Disputing does NOT reverse the approval(s) — there is no "reopen"
     * path in WorkflowService::completeStage(), and unwinding an
     * already-finalized document (possibly already notified/archived) is
     * unsafe — instead it sets disputed_at once (global_status is left
     * as-is, so the document's approval history stays intact) and asks the
     * originator to resubmit a corrected version.
     */
    public function reviewAutoApproval(Request $request, DocumentRepository $document)
    {
        $pending = DocumentAssignment::where('document_id', $document->document_id)
            ->where('auto_approved', true)
            ->whereNull('admin_reviewed_at')
            ->with('stage')
            ->get();

        abort_if($pending->isEmpty(), 404);

        $validated = $request->validate([
            'outcome' => ['required', 'in:confirmed,disputed'],
            'note' => ['required_if:outcome,disputed', 'nullable', 'string', 'max:1000'],
        ]);

        $admin = $request->user();
        $note = $validated['note'] ?? null;
        $stageNames = $pending->pluck('stage.stage_name')->all();

        foreach ($pending as $assignment) {
            $assignment->admin_reviewed_at = now();
            $assignment->admin_reviewed_by = $admin->user_id;
            $assignment->admin_review_note = $note;
            $assignment->admin_review_outcome = $validated['outcome'];
            $assignment->save();
        }

        $stageList = implode(', ', $stageNames);

        if ($validated['outcome'] === 'confirmed') {
            AuditLog::record($admin->user_id, $document->document_id, 'admin_review',
                "Confirmed auto-approved stage(s) '{$stageList}'." . ($note ? " Note: \"{$note}\"" : ''));

            return back()->with('status', 'Marked as reviewed.');
        }

        $document->disputed_at = now();
        $document->save();

        AuditLog::record($admin->user_id, $document->document_id, 'admin_dispute',
            "Disputed auto-approved stage(s) '{$stageList}': \"{$note}\"");

        NotificationRecord::send($document->originator_id, $document->document_id,
            "Your document '{$document->title}' was auto-approved by the system, but an Admin has disputed it: \"{$note}\". Please resubmit a corrected version.", 'high');

        if ($document->originator->email) {
            Mail::to($document->originator->email)->queue(new AutoApprovalDisputedMail($document, $stageNames, $note));
        }

        foreach (User::where('role', 'admin')->where('is_active', true)->where('user_id', '!=', $admin->user_id)->get() as $other) {
            NotificationRecord::send($other->user_id, $document->document_id,
                "{$admin->full_name} disputed the system's auto-approval of '{$document->title}': \"{$note}\".", 'high');
        }

        return back()->with('status', 'Disputed — the originator has been notified to resubmit.');
    }

    public function override(Request $request, DocumentAssignment $assignment)
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->sla->adminOverride($assignment, $request->user(), $validated['decision'], $validated['comments'] ?? null);

        return back()->with('status', 'Override applied: ' . ucfirst($validated['decision']) . '.');
    }

    /**
     * Overrides every breached stage assigned to the SAME approver for one
     * document in a single action — mirrors
     * ApprovalController::decideBatch() so the SLA queue doesn't show one
     * override form per stage when a single approver is holding more than
     * one breached stage for the same document.
     */
    public function overrideBatch(Request $request)
    {
        $validated = $request->validate([
            'assignment_ids' => ['required', 'array', 'min:1'],
            'assignment_ids.*' => ['integer', 'exists:document_assignments,assignment_id'],
            'decision' => ['required', 'in:approved,rejected'],
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        $assignments = DocumentAssignment::whereIn('assignment_id', $validated['assignment_ids'])
            ->where('escalated_to_admin', true)
            ->whereNull('admin_override_at')
            ->where('individual_status', 'pending')
            ->get();

        abort_if($assignments->isEmpty(), 409, 'These assignments have already been actioned.');

        foreach ($assignments as $assignment) {
            $assignment->refresh();
            if ($assignment->individual_status !== 'pending') {
                continue; // already closed as a side effect of an earlier iteration (e.g. rejection cascade)
            }
            $this->sla->adminOverride($assignment, $request->user(), $validated['decision'], $validated['comments'] ?? null);
        }

        return back()->with('status', 'Override applied: ' . ucfirst($validated['decision']) . '.');
    }

    // ---------------------------------------------------------------
    // Workflow stage configuration
    // ---------------------------------------------------------------

    public function workflowConfig()
    {
        $stages = WorkflowStage::orderBy('document_category')->orderBy('sequence_order')->get()->groupBy('document_category');
        $categories = ValidationService::knownCategories();

        // Section 2: orphan-prevention data — how many PENDING assignments
        // (blocks archive/delete) vs. any assignment ever (blocks hard
        // delete; forces archive instead) each stage has.
        $activeCounts = DocumentAssignment::where('individual_status', 'pending')
            ->select('stage_id')->selectRaw('count(*) as cnt')->groupBy('stage_id')->pluck('cnt', 'stage_id');
        $historyCounts = DocumentAssignment::select('stage_id')->selectRaw('count(*) as cnt')->groupBy('stage_id')->pluck('cnt', 'stage_id');

        // The actual pending assignments blocking archive/delete, so the
        // Admin can resolve each one directly (approve/reject on the
        // approver's behalf via the same SlaService::adminOverride() used
        // by the SLA Override Queue) instead of reassigning the document
        // to a stage its approver isn't actually eligible for.
        $pendingByStage = DocumentAssignment::where('individual_status', 'pending')
            ->with('document')
            ->get()
            ->groupBy('stage_id');

        return view('admin.workflow_config', compact('stages', 'categories', 'activeCounts', 'historyCounts', 'pendingByStage'));
    }

    public function storeStage(Request $request)
    {
        $validated = $request->validate([
            'document_category' => ['required', 'in:' . implode(',', ValidationService::knownCategories())],
            'stage_name' => ['required', 'string', 'max:255'],
            'sequence_order' => ['required', 'integer', 'min:1', 'max:20'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $stage = WorkflowStage::create($validated);

        AuditLog::record($request->user()->user_id, null, 'workflow_config',
            "Added workflow stage '{$stage->stage_name}' for '{$stage->document_category}' (order {$stage->sequence_order}).");

        return back()->with('status', 'Workflow stage saved.');
    }

    public function updateStage(Request $request, WorkflowStage $stage)
    {
        $validated = $request->validate([
            'stage_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $oldName = $stage->stage_name;
        $stage->update($validated);

        AuditLog::record($request->user()->user_id, null, 'workflow_config',
            "Renamed/edited workflow stage #{$stage->stage_id} ('{$stage->stage_name}').");

        $this->notifyApproversOfStageChange($stage, "updated the '{$oldName}' stage's details to '{$stage->stage_name}'");

        return back()->with('status', 'Stage updated.');
    }

    /**
     * Confirms to every approver currently holding a pending assignment on
     * this stage that an edit/archive already happened. In-app only,
     * purely a record of what occurred — does not block or delay the
     * Admin's action. See notifyPendingApprovers() for the ADVANCE notice
     * sent before the Admin acts, which is the one meant to actually give
     * the approver a chance to review first.
     */
    private function notifyApproversOfStageChange(WorkflowStage $stage, string $what): void
    {
        $approverIds = DocumentAssignment::where('stage_id', $stage->stage_id)
            ->where('individual_status', 'pending')
            ->distinct()
            ->pluck('user_id');

        foreach ($approverIds as $approverId) {
            NotificationRecord::send($approverId, null,
                "An Admin {$what} — you have a pending document on this stage; nothing about your task itself changed, but the stage details did.");
        }
    }

    /**
     * Section 3/4: sent BEFORE the Admin edits or archives a stage — an
     * explicit, separate action the Admin triggers to give each affected
     * approver a heads-up and a real chance to review/act on their own
     * pending document(s) first, rather than the Admin immediately
     * overriding them via "Review & decide pending". High priority since
     * it's time-sensitive; does not itself change or block anything —
     * the Admin decides when enough time has passed to proceed.
     */
    public function notifyPendingApprovers(Request $request, WorkflowStage $stage)
    {
        $pending = DocumentAssignment::where('stage_id', $stage->stage_id)
            ->where('individual_status', 'pending')
            ->with('document')
            ->get();

        abort_if($pending->isEmpty(), 409, 'No pending assignments on this stage to notify about.');

        foreach ($pending->unique('user_id') as $assignment) {
            NotificationRecord::send($assignment->user_id, null,
                "Heads up: an Admin is planning to edit or archive the '{$stage->stage_name}' stage soon. " .
                "Please review and act on your pending document(s) for it as soon as you can, before the Admin steps in on your behalf.",
                'high');
        }

        AuditLog::record($request->user()->user_id, null, 'workflow_config',
            "Notified " . $pending->unique('user_id')->count() . " approver(s) with pending work on stage '{$stage->stage_name}' ahead of a planned edit/archive.");

        return back()->with('status', 'Approver(s) notified — give them time to review before editing or archiving.');
    }

    public function moveStageUp(Request $request, WorkflowStage $stage)
    {
        $this->swapStageOrder($request, $stage, 'up');
        return back();
    }

    public function moveStageDown(Request $request, WorkflowStage $stage)
    {
        $this->swapStageOrder($request, $stage, 'down');
        return back();
    }

    private function swapStageOrder(Request $request, WorkflowStage $stage, string $direction): void
    {
        $neighbor = WorkflowStage::where('document_category', $stage->document_category)
            ->where('is_archived', false)
            ->where('stage_id', '!=', $stage->stage_id)
            ->where('sequence_order', $direction === 'up' ? '<=' : '>=', $stage->sequence_order)
            ->orderBy('sequence_order', $direction === 'up' ? 'desc' : 'asc')
            ->first();

        if (!$neighbor) {
            return;
        }

        [$a, $b] = [$stage->sequence_order, $neighbor->sequence_order];
        $stage->update(['sequence_order' => $b]);
        $neighbor->update(['sequence_order' => $a]);

        AuditLog::record($request->user()->user_id, null, 'workflow_config', "Reordered stage '{$stage->stage_name}'.");
    }

    public function archiveStage(Request $request, WorkflowStage $stage)
    {
        abort_if($this->stageHasActiveAssignments($stage), 409,
            'This stage has active (pending) assignments. Resolve them first — see "Review & decide pending" below.');

        $this->notifyApproversOfStageChange($stage, "archived the '{$stage->stage_name}' stage");

        $stage->update(['is_archived' => true]);

        AuditLog::record($request->user()->user_id, null, 'workflow_config', "Archived stage '{$stage->stage_name}'.");

        return back()->with('status', 'Stage archived.');
    }

    public function unarchiveStage(Request $request, WorkflowStage $stage)
    {
        $stage->update(['is_archived' => false]);

        AuditLog::record($request->user()->user_id, null, 'workflow_config', "Unarchived stage '{$stage->stage_name}'.");

        return back()->with('status', 'Stage unarchived.');
    }

    public function destroyStage(Request $request, WorkflowStage $stage)
    {
        abort_if($this->stageHasActiveAssignments($stage), 409,
            'This stage has active (pending) assignments. Resolve them first — see "Review & decide pending" below.');

        abort_if(DocumentAssignment::where('stage_id', $stage->stage_id)->exists(), 409,
            'This stage has historical assignment history and cannot be permanently deleted — archive it instead.');

        $name = $stage->stage_name;
        $stage->delete();

        AuditLog::record($request->user()->user_id, null, 'workflow_config', "Deleted unused stage '{$name}'.");

        return back()->with('status', 'Stage deleted.');
    }

    private function stageHasActiveAssignments(WorkflowStage $stage): bool
    {
        return DocumentAssignment::where('stage_id', $stage->stage_id)->where('individual_status', 'pending')->exists();
    }

    // ---------------------------------------------------------------
    // Operational Window Controls & Holiday Management (Section 1)
    // ---------------------------------------------------------------

    public function calendar(Request $request)
    {
        $month = $request->filled('month') ? Carbon::parse($request->string('month') . '-01') : now()->startOfMonth();

        $holidays = SlaHoliday::whereBetween('holiday_date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->get()
            ->keyBy(fn (SlaHoliday $h) => $h->holiday_date->toDateString());

        $settings = SlaSetting::current();

        return view('admin.calendar', compact('month', 'holidays', 'settings'));
    }

    public function updateSlaSettings(Request $request)
    {
        $validated = $request->validate([
            'work_start_time' => ['required', 'date_format:H:i'],
            'work_end_time' => ['required', 'date_format:H:i', 'after:work_start_time'],
            'working_days' => ['required', 'array', 'min:1'],
            'working_days.*' => ['integer', 'between:0,6'],
        ]);

        $settings = SlaSetting::current();
        $settings->update($validated + ['updated_by' => $request->user()->user_id]);

        AuditLog::record($request->user()->user_id, null, 'sla_settings_update', 'Updated business-hours working window.');

        $sync = $this->workflow->syncDueDatesWithCalendar();

        return back()->with('status', 'Working hours updated.' . $this->calendarSyncSummary($sync));
    }

    public function storeHoliday(Request $request)
    {
        $validated = $request->validate([
            'holiday_date' => ['required', 'date', 'unique:sla_holidays,holiday_date'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        SlaHoliday::create($validated + ['created_by' => $request->user()->user_id]);

        AuditLog::record($request->user()->user_id, null, 'sla_holiday_add', "Marked {$validated['holiday_date']} as a non-working day.");

        // Section 1: a newly-marked holiday must (a) push forward the due
        // date of any in-flight document that was already using that day
        // as its hard deadline, and (b) retroactively recalculate every
        // already-routed pending assignment's SLA window that spans it —
        // both are otherwise "computed once, stored statically" at
        // routing/submission time and would silently stay wrong.
        $sync = $this->workflow->syncDueDatesWithCalendar();

        return back()->with('status', 'Holiday added.' . $this->calendarSyncSummary($sync));
    }

    public function destroyHoliday(Request $request, SlaHoliday $holiday)
    {
        $date = $holiday->holiday_date->toDateString();
        $holiday->delete();

        AuditLog::record($request->user()->user_id, null, 'sla_holiday_remove', "Unmarked {$date} as a non-working day.");

        // Removing a holiday only ever frees up time — it can't invalidate
        // an existing due date — but SLA windows still need re-syncing
        // since more business time may now be available before the
        // (unchanged) due date than was assumed when they were computed.
        $changed = $this->workflow->recalculatePendingSlaDeadlines();

        return back()->with('status', 'Holiday removed.' . ($changed ? " {$changed} pending assignment(s) had their SLA deadline recalculated." : ''));
    }

    private function calendarSyncSummary(array $sync): string
    {
        $parts = [];
        if ($sync['documents_shifted'] > 0) {
            $parts[] = "{$sync['documents_shifted']} document(s) had their due date moved off a now-non-working day";
        }
        if ($sync['assignments_recalculated'] > 0) {
            $parts[] = "{$sync['assignments_recalculated']} pending assignment(s) had their SLA deadline recalculated";
        }

        return $parts ? ' ' . implode('; ', $parts) . '.' : '';
    }

    // ---------------------------------------------------------------
    // SLA Violation reporting (Section 4)
    // ---------------------------------------------------------------

    public function violationsReport(Request $request)
    {
        $query = $this->violationsQuery($request);

        // Same "folders first" pattern as the Archive (Feature: browse by
        // category) — but unlike the Archive, the stat cards and the
        // approver-roster control stay visible either way; only the
        // filter form + results list are gated behind picking a category
        // (or searching), since those are the pieces that don't mean
        // anything until you've narrowed down to something.
        $hasActiveFilters = $request->filled('category') || $request->filled('document')
            || $request->filled('date_from') || $request->filled('date_to');
        $showFolders = !$hasActiveFilters;

        $violations = $showFolders ? null : (clone $query)
            ->with(['document', 'approver', 'assignment.adminOverrideBy', 'assignment.approver'])
            ->orderByDesc('violation_timestamp')->paginate(20)->withQueryString();

        return view('admin.sla_violations', array_merge($this->violationStats($query, $request), [
            'showFolders' => $showFolders,
            'folders' => $showFolders ? $this->violationFolderStats() : null,
            'violations' => $violations,
            'categories' => ValidationService::knownCategories(),
        ]));
    }

    /**
     * Live search (Feature: instant results as you type) — identical
     * query/pagination as violationsReport()'s results branch, via the
     * shared helpers below, returning just the results fragment.
     */
    public function violationsRefresh(Request $request)
    {
        $violations = (clone $this->violationsQuery($request))
            ->with(['document', 'approver', 'assignment.adminOverrideBy', 'assignment.approver'])
            ->orderByDesc('violation_timestamp')->paginate(20)->withQueryString();

        return view('admin.partials.violations_results', compact('violations'));
    }

    private function violationsQuery(Request $request)
    {
        $query = SlaViolation::query();

        if ($request->filled('document')) {
            $term = $request->string('document');
            $query->whereHas('document', fn ($q) => $q->where('title', 'like', "%{$term}%"));
        }
        if ($request->filled('category')) {
            $category = $request->string('category');
            $query->whereHas('document', fn ($q) => $q->where('ml_category', $category));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('violation_timestamp', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('violation_timestamp', '<=', $request->date('date_to'));
        }

        return $query;
    }

    /** One row per category for the folder-grid landing screen. */
    private function violationFolderStats()
    {
        return collect(ValidationService::knownCategories())->map(fn ($category) => (object) [
            'category' => $category,
            'total' => SlaViolation::whereHas('document', fn ($q) => $q->where('ml_category', $category))->count(),
        ]);
    }

    /**
     * Everything the stat cards + approver roster need — always computed
     * regardless of $showFolders, since those stay visible on both screens.
     */
    private function violationStats($query, Request $request): array
    {
        $byApprover = (clone $query)->selectRaw('approver_id, count(*) as total')
            ->groupBy('approver_id')->with('approver')->orderByDesc('total')->limit(5)->get();

        $byStage = (clone $query)->selectRaw('stage_name, count(*) as total')
            ->groupBy('stage_name')->orderByDesc('total')->limit(5)->get();

        // Top Category — parallels Top Approver/Top Bottleneck Stage, and
        // ties directly into the category-folder browsing above.
        $byCategory = (clone $query)
            ->join('document_repository', 'sla_violations.document_id', '=', 'document_repository.document_id')
            ->selectRaw('document_repository.ml_category, count(*) as total')
            ->groupBy('document_repository.ml_category')
            ->orderByDesc('total')
            ->first();

        // Disputed — how many of these breaches were later flagged by an
        // Admin as a bad auto-approval (see AdminController::
        // reviewAutoApproval()). Otherwise only visible per-row as a badge,
        // never as a total anywhere on this page.
        $disputedCount = (clone $query)->whereHas('document', fn ($q) => $q->whereNotNull('disputed_at'))->count();

        $totalCount = (clone $query)->count();
        $avgOverdue = (clone $query)->avg('duration_overdue');

        // Full roster for the "Top Approver" card's expanded view — EVERY
        // approver, not just the ones with breaches, so a clean record is
        // visible too, not just a leaderboard of offenders. breach_count
        // respects the same filters as the rest of this report (so
        // narrowing the date range/category above narrows this too);
        // assignment_count is unfiltered by date (a lifetime total) so
        // "0 breaches" can be read against "0 of 0 assignments" (never
        // given work yet) vs "0 of 50" (a genuinely clean record).
        $approverRoster = User::where('role', 'approver')
            ->withCount([
                'slaViolations as breach_count' => function ($q) use ($request) {
                    if ($request->filled('document')) {
                        $term = $request->string('document');
                        $q->whereHas('document', fn ($dq) => $dq->where('title', 'like', "%{$term}%"));
                    }
                    if ($request->filled('category')) {
                        $category = $request->string('category');
                        $q->whereHas('document', fn ($dq) => $dq->where('ml_category', $category));
                    }
                    if ($request->filled('date_from')) {
                        $q->whereDate('violation_timestamp', '>=', $request->date('date_from'));
                    }
                    if ($request->filled('date_to')) {
                        $q->whereDate('violation_timestamp', '<=', $request->date('date_to'));
                    }
                },
                'assignmentsAsApprover as assignment_count' => function ($q) use ($request) {
                    if ($request->filled('category')) {
                        $category = $request->string('category');
                        $q->whereHas('document', fn ($dq) => $dq->where('ml_category', $category));
                    }
                },
            ])
            ->orderByDesc('breach_count')
            ->orderBy('full_name')
            ->get();

        // Per-approver breakdown by category, for the roster's nested
        // reveal. Not redundant with assigned_category: approvers can be
        // reassigned to a different category over time (see
        // AdminController::updateApproverStages()), but a SlaViolation
        // records the category the DOCUMENT was in at breach time, not the
        // approver's current assignment — so someone reassigned mid-tenure
        // can legitimately have breach history split across categories
        // that the roster's single lumped total would otherwise hide.
        $byApproverCategory = (clone $query)
            ->join('document_repository', 'sla_violations.document_id', '=', 'document_repository.document_id')
            ->selectRaw('sla_violations.approver_id, document_repository.ml_category, count(*) as total')
            ->groupBy('sla_violations.approver_id', 'document_repository.ml_category')
            ->orderByDesc('total')
            ->get()
            ->groupBy('approver_id');

        return [
            'byApprover' => $byApprover,
            'approverRoster' => $approverRoster,
            'byApproverCategory' => $byApproverCategory,
            'byStage' => $byStage,
            'byCategory' => $byCategory,
            'disputedCount' => $disputedCount,
            'totalCount' => $totalCount,
            'avgOverdue' => round($avgOverdue ?? 0),
        ];
    }

    // ---------------------------------------------------------------
    // Audit trail viewer (Section 6)
    // ---------------------------------------------------------------

    /**
     * Session/auth events — hidden by default (see auditLogs()) since they
     * dominate the list without being what's usually being audited for.
     * Covers both the web session login/logout and the API's token-based
     * equivalents (Api\AuthController) — same "routine noise" category,
     * just a different transport, so the "Show login/logout events"
     * checkbox should hide/reveal both consistently rather than only
     * filtering the web ones.
     */
    private const AUDIT_SESSION_ACTIONS = ['login', 'logout', 'api_login', 'api_logout'];

    public function auditLogs(Request $request)
    {
        $query = AuditLog::with(['user', 'document'])->orderByDesc('timestamp');

        if ($request->filled('action_type')) {
            $query->where('action_type', $request->string('action_type'));
        }
        if ($request->filled('document')) {
            // Matches either a document title substring or, if the term
            // looks numeric (with or without a leading "#"), the exact
            // document_id — so "47" or "#47" both find it directly
            // without needing to know/guess the title.
            $term = trim($request->string('document'));
            $numericId = ltrim($term, '#');

            $query->where(function ($q) use ($term, $numericId) {
                $q->whereHas('document', fn ($q2) => $q2->where('title', 'like', "%{$term}%"));
                if ($numericId !== '' && ctype_digit($numericId)) {
                    $q->orWhere('document_id', (int) $numericId);
                }
            });
        }
        if ($request->filled('actor_id')) {
            $query->where('user_id', $request->integer('actor_id'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('timestamp', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('timestamp', '<=', $request->date('date_to'));
        }
        if (!$request->boolean('show_session')) {
            $query->whereNotIn('action_type', self::AUDIT_SESSION_ACTIONS);
        }

        $logs = $query->paginate(25)->withQueryString();

        $actionTypes = AuditLog::select('action_type')->distinct()->orderBy('action_type')->pluck('action_type');
        $actors = User::orderBy('full_name')->get(['user_id', 'full_name']);

        return view('admin.audit_logs', compact('logs', 'actionTypes', 'actors'));
    }
}