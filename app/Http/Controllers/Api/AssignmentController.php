<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssignmentResource;
use App\Models\DocumentAssignment;
use App\Services\SlaService;
use App\Services\WorkflowService;
use Illuminate\Http\Request;

/**
 * JSON equivalent of the approver dashboard/decide flow
 * (App\Http\Controllers\ApprovalController) — same ownership checks, same
 * on-demand SLA escalation guard, same WorkflowService::decide() call.
 * Deliberately a flat list rather than the web dashboard's nested
 * batch/document/stage container grouping — that grouping exists for
 * human-readable Blade rendering; an API client is better served by a
 * plain array it can sort/group itself.
 */
class AssignmentController extends Controller
{
    public function __construct(private WorkflowService $workflow, private SlaService $sla) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->isApprover(), 403, 'Only approver accounts have assignments.');

        $userId = $request->user()->user_id;

        DocumentAssignment::where('user_id', $userId)
            ->where('individual_status', 'pending')
            ->where('sla_expires_at', '<', now())
            ->with(['stage', 'document', 'approver'])
            ->get()
            ->each(fn (DocumentAssignment $a) => $this->sla->autoApproveMissedDeadline($a));

        $assignments = DocumentAssignment::where('user_id', $userId)
            ->where('individual_status', 'pending')
            ->with(['document', 'stage'])
            ->orderBy('priority_rank')
            ->orderBy('sla_expires_at')
            ->paginate(20);

        return AssignmentResource::collection($assignments);
    }

    public function decide(Request $request, DocumentAssignment $assignment)
    {
        $this->authorize('decide', $assignment);

        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        abort_if($assignment->individual_status !== 'pending', 409, 'This assignment has already been actioned.');

        if ($assignment->sla_expires_at && now()->greaterThan($assignment->sla_expires_at)) {
            $this->sla->autoApproveMissedDeadline($assignment);
            $assignment->refresh(); // autoApproveMissedDeadline() worked on its own locked copy — re-read what it did
        }
        abort_if($assignment->individual_status !== 'pending', 409, "This assignment's SLA deadline has passed — the system auto-approved it, so it can no longer be decided here.");

        $this->workflow->decide($assignment, $request->user(), $validated['decision'], $validated['comments'] ?? null);

        return new AssignmentResource($assignment->fresh(['document', 'stage']));
    }
}
