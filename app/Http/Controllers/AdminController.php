<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\MlModelRepository;
use App\Models\NotificationRecord;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\ClassificationService;
use App\Services\SlaService;
use App\Services\TextExtractionService;
use App\Services\ValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function __construct(
        private ClassificationService $classifier,
        private TextExtractionService $extractor,
        private SlaService $sla,
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
        $stagesByCategory = WorkflowStage::orderBy('sequence_order')->get()->groupBy('document_category');
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

        $stages = WorkflowStage::forCategory($user->assigned_category)->get();
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

    public function slaQueue()
    {
        $assignments = DocumentAssignment::where('escalated_to_admin', true)
            ->whereNull('admin_override_at')
            ->where('individual_status', 'pending')
            ->with(['document', 'stage', 'approver'])
            ->orderBy('sla_expires_at')
            ->paginate(10);

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

    // ---------------------------------------------------------------
    // Workflow stage configuration
    // ---------------------------------------------------------------

    public function workflowConfig()
    {
        $stages = WorkflowStage::orderBy('document_category')->orderBy('sequence_order')->get()->groupBy('document_category');
        $categories = ValidationService::knownCategories();

        return view('admin.workflow_config', compact('stages', 'categories'));
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