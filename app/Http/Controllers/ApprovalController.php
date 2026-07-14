<?php

namespace App\Http\Controllers;

use App\Models\DocumentAssignment;
use App\Services\WorkflowService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class ApprovalController extends Controller
{
    public function __construct(private WorkflowService $workflow)
    {
    }

    /**
     * Approver dashboard: action-oriented review queue with SLA countdowns.
     *
     * Requests are rendered as nested containers, two levels deep:
     *   - Outer: the SubmissionBatch a document arrived in (Feature:
     *     grouped approval requests) — documents an Originator uploaded
     *     together stay visually together, with the shared due date shown
     *     once at the container level. A document with no batch (legacy
     *     data, or a lone single-file submission) becomes a container of
     *     its own.
     *   - Inner: each document's own pending stage assignment(s) — the
     *     solo-approver shortcut can leave more than one stage pending for
     *     the same document at once, so those still nest under that one
     *     document card rather than duplicating it.
     */
    public function dashboard(Request $request)
    {
        $pending = DocumentAssignment::pendingFor($request->user()->user_id)
            ->with(['document.batch', 'document.originator', 'document.assignments.approver', 'stage'])
            ->orderBy('priority_rank')
            ->orderBy('sla_expires_at')
            ->get();

        $containers = $pending
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

        $containers = new LengthAwarePaginator(
            $containers->forPage($page, $perPage)->values(),
            $containers->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('approver.dashboard', compact('containers'));
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