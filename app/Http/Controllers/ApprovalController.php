<?php

namespace App\Http\Controllers;

use App\Models\DocumentAssignment;
use App\Services\WorkflowService;
use Illuminate\Http\Request;

class ApprovalController extends Controller
{
    public function __construct(private WorkflowService $workflow)
    {
    }

    /**
     * Approver dashboard: action-oriented review queue with SLA countdowns.
     * Assignments are grouped by document so multiple pending stages for
     * the SAME document (solo-approver shortcut) render as one document
     * card with each stage nested inside it, rather than several flat,
     * confusingly-similar cards for the same file.
     */
    public function dashboard(Request $request)
    {
        $assignments = DocumentAssignment::pendingFor($request->user()->user_id)
            ->with(['document', 'stage'])
            ->orderBy('priority_rank')
            ->orderBy('sla_expires_at')
            ->paginate(10);

        $grouped = collect($assignments->items())->groupBy('document_id');

        return view('approver.dashboard', compact('assignments', 'grouped'));
    }

    public function decide(Request $request, DocumentAssignment $assignment)
    {
        abort_unless($assignment->user_id === $request->user()->user_id, 403);

        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        abort_if($assignment->individual_status !== 'pending', 409, 'This assignment has already been actioned.');

        $this->workflow->decide($assignment, $request->user(), $validated['decision'], $validated['comments'] ?? null);

        return redirect()->route('approver.dashboard')->with('status', 'Decision recorded: ' . ucfirst($validated['decision']) . '.');
    }

    /**
     * Self-service "busy/away" toggle (Feature: load-balancing fallback).
     * A busy approver is skipped by WorkflowService's eligibility logic in
     * favor of an available peer on the same stage, unless doing so would
     * leave nobody eligible at all.
     */
    public function toggleAvailability(Request $request)
    {
        $user = $request->user();
        $user->is_busy = !$user->is_busy;
        $user->save();

        return back()->with('status', $user->is_busy
            ? "You're now marked as busy/away — new documents will route to an available peer where possible."
            : "You're now marked as available.");
    }
}