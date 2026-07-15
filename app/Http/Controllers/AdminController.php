<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\MlModelRepository;
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

    /** Admin control-center overview. */
    public function dashboard()
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

        $activeModel = MlModelRepository::active();

        return view('admin.dashboard', compact('stats', 'slaAlerts', 'activeModel'));
    }

    // ---------------------------------------------------------------
    // User account management (Section 3: Account ID <-> workflow role)
    // ---------------------------------------------------------------

    public function users()
    {
        $users = User::with(['createdBy', 'workflowStages'])->orderBy('role')->paginate(15);
        $stagesByCategory = WorkflowStage::where('is_archived', false)->orderBy('sequence_order')->get()->groupBy('document_category');
        return view('admin.users', compact('users', 'stagesByCategory'));
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

        return back()->with('status', "Account '{$user->username}' created.");
    }

    /** Admin-only: view/edit which specific stages an approver is restricted to. */
    public function editApproverStages(User $user)
    {
        abort_unless($user->role === 'approver', 422, 'Only approver accounts have stage assignments.');

        $stages = WorkflowStage::forCategory($user->assigned_category)->where('is_archived', false)->get();
        $assignedStageIds = $user->workflowStages()->pluck('workflow_stages.stage_id')->all();

        return view('admin.approver_stages', compact('user', 'stages', 'assignedStageIds'));
    }

    /**
     * Updates which specific stages an approver handles (Feature: Dynamic
     * Workflow Assignment). The approver's category itself remains locked
     * at creation — this only scopes them within that fixed category.
     * Leaving every checkbox unchecked resets them to "eligible for every
     * stage in my category" (the default, unrestricted behavior).
     */
    public function updateApproverStages(Request $request, User $user)
    {
        abort_unless($user->role === 'approver', 422, 'Only approver accounts have stage assignments.');

        $validStageIds = WorkflowStage::where('document_category', $user->assigned_category)->pluck('stage_id');

        $validated = $request->validate([
            'stage_ids' => ['nullable', 'array'],
            'stage_ids.*' => ['integer', Rule::in($validStageIds)],
        ]);

        $user->workflowStages()->sync($validated['stage_ids'] ?? []);

        AuditLog::record($request->user()->user_id, null, 'assign_stages',
            "Updated stage assignments for {$user->full_name} (#{$user->user_id}): " .
            (empty($validated['stage_ids']) ? 'all stages in category (no restriction).' : implode(', ', $validated['stage_ids'])));

        return redirect()->route('admin.users')->with('status', "Stage assignments updated for {$user->full_name}.");
    }

    public function toggleUser(Request $request, User $user)
    {
        $user->is_active = !$user->is_active;
        $user->save();

        AuditLog::record($request->user()->user_id, null, 'user_toggle',
            "Account #{$user->user_id} ({$user->username}) set to " . ($user->is_active ? 'active' : 'inactive') . '.');

        return back()->with('status', 'Account status updated.');
    }

    // ---------------------------------------------------------------
    // ML dataset training (5–10 sample uploads per category — Scope 1.4)
    // ---------------------------------------------------------------

    public function mlTraining()
    {
        $categories = ValidationService::knownCategories();
        $activeModel = MlModelRepository::active();
        $history = MlModelRepository::orderByDesc('last_trained')->limit(10)->get();

        return view('admin.ml_training', compact('categories', 'activeModel', 'history'));
    }

    public function trainModel(Request $request)
    {
        $categories = ValidationService::knownCategories();

        $rules = [];
        foreach ($categories as $category) {
            $key = 'samples_' . str_replace(' ', '_', strtolower($category));
            $rules[$key] = ['required', 'array', 'min:5', 'max:10'];
            $rules[$key . '.*'] = ['required', 'file', 'mimes:pdf,txt,docx', 'max:10240'];
        }
        $validated = $request->validate($rules);

        $samplesByCategory = [];
        foreach ($categories as $category) {
            $key = 'samples_' . str_replace(' ', '_', strtolower($category));
            $texts = [];
            foreach ($validated[$key] as $file) {
                $texts[] = $this->extractor->extract($file)['text'];
            }
            $samplesByCategory[$category] = $texts;
        }

        $model = $this->classifier->train($samplesByCategory);

        AuditLog::record($request->user()->user_id, null, 'ml_train',
            "Trained model #{$model->model_id} ({$model->version}) on {$model->training_sample_count} samples across " . count($categories) . ' categories. Estimated accuracy: ' . $model->accuracy_score . '%.');

        return back()->with('status', "Model {$model->version} trained successfully (est. accuracy {$model->accuracy_score}%).");
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

        return view('admin.sla_queue', compact('assignments'));
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

        return view('admin.workflow_config', compact('stages', 'categories', 'activeCounts', 'historyCounts'));
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

        $stage->update($validated);

        AuditLog::record($request->user()->user_id, null, 'workflow_config',
            "Renamed/edited workflow stage #{$stage->stage_id} ('{$stage->stage_name}').");

        return back()->with('status', 'Stage updated.');
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
            'This stage has active (pending) assignments. Reassign them to another stage first.');

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

    public function reassignStage(Request $request, WorkflowStage $stage)
    {
        $validated = $request->validate([
            'target_stage_id' => [
                'required',
                'integer',
                Rule::exists('workflow_stages', 'stage_id')
                    ->where('document_category', $stage->document_category)
                    ->where('is_archived', false),
            ],
        ]);

        abort_if((int) $validated['target_stage_id'] === $stage->stage_id, 422, 'Choose a different stage to reassign to.');

        // Reassignment is an admin-privileged manual correction, not a new
        // routing event — it intentionally does not recompute sla_expires_at
        // or re-check approver eligibility against the target stage.
        $count = DocumentAssignment::where('stage_id', $stage->stage_id)
            ->where('individual_status', 'pending')
            ->update(['stage_id' => $validated['target_stage_id']]);

        AuditLog::record($request->user()->user_id, null, 'workflow_config',
            "Reassigned {$count} pending assignment(s) from stage '{$stage->stage_name}' to stage #{$validated['target_stage_id']}.");

        return back()->with('status', "{$count} assignment(s) reassigned.");
    }

    public function destroyStage(Request $request, WorkflowStage $stage)
    {
        abort_if($this->stageHasActiveAssignments($stage), 409,
            'This stage has active (pending) assignments. Archive it or reassign them first.');

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

        $changed = $this->workflow->recalculatePendingSlaDeadlines();

        return back()->with('status', "Working hours updated." . ($changed ? " {$changed} pending assignment(s) had their SLA deadline recalculated." : ''));
    }

    public function storeHoliday(Request $request)
    {
        $validated = $request->validate([
            'holiday_date' => ['required', 'date', 'unique:sla_holidays,holiday_date'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        SlaHoliday::create($validated + ['created_by' => $request->user()->user_id]);

        AuditLog::record($request->user()->user_id, null, 'sla_holiday_add', "Marked {$validated['holiday_date']} as a non-working day.");

        // Section 1: a newly-marked holiday must retroactively shift the
        // deadline of every already-routed pending assignment that spans
        // it — sla_expires_at is otherwise "computed once, stored
        // statically" at routing time and would silently stay wrong.
        $changed = $this->workflow->recalculatePendingSlaDeadlines();

        return back()->with('status', "Holiday added." . ($changed ? " {$changed} pending assignment(s) had their SLA deadline recalculated." : ''));
    }

    public function destroyHoliday(Request $request, SlaHoliday $holiday)
    {
        $date = $holiday->holiday_date->toDateString();
        $holiday->delete();

        AuditLog::record($request->user()->user_id, null, 'sla_holiday_remove', "Unmarked {$date} as a non-working day.");

        $changed = $this->workflow->recalculatePendingSlaDeadlines();

        return back()->with('status', "Holiday removed." . ($changed ? " {$changed} pending assignment(s) had their SLA deadline recalculated." : ''));
    }

    // ---------------------------------------------------------------
    // SLA Violation reporting (Section 4)
    // ---------------------------------------------------------------

    public function violationsReport(Request $request)
    {
        $query = SlaViolation::query();

        if ($request->filled('approver_id')) {
            $query->where('approver_id', $request->integer('approver_id'));
        }
        if ($request->filled('stage_name')) {
            $query->where('stage_name', $request->string('stage_name'));
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

        $violations = (clone $query)->with(['document', 'approver'])
            ->orderByDesc('violation_timestamp')->paginate(20)->withQueryString();

        $byApprover = (clone $query)->selectRaw('approver_id, count(*) as total')
            ->groupBy('approver_id')->with('approver')->orderByDesc('total')->limit(5)->get();

        $byStage = (clone $query)->selectRaw('stage_name, count(*) as total')
            ->groupBy('stage_name')->orderByDesc('total')->limit(5)->get();

        $totalCount = (clone $query)->count();
        $avgOverdue = (clone $query)->avg('duration_overdue');

        return view('admin.sla_violations', [
            'violations' => $violations,
            'byApprover' => $byApprover,
            'byStage' => $byStage,
            'totalCount' => $totalCount,
            'avgOverdue' => round($avgOverdue ?? 0),
            'categories' => ValidationService::knownCategories(),
        ]);
    }

    // ---------------------------------------------------------------
    // Audit trail viewer (Section 6)
    // ---------------------------------------------------------------

    public function auditLogs(Request $request)
    {
        $query = AuditLog::with(['user', 'document'])->orderByDesc('timestamp');

        if ($request->filled('action_type')) {
            $query->where('action_type', $request->string('action_type'));
        }
        if ($request->filled('document_id')) {
            $query->where('document_id', (int) $request->integer('document_id'));
        }

        $logs = $query->paginate(25)->withQueryString();

        return view('admin.audit_logs', compact('logs'));
    }
}