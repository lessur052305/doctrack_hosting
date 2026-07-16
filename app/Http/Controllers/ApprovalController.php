<?php

namespace App\Http\Controllers;

use App\Models\DocumentAssignment;
use App\Services\SlaService;
use App\Services\WorkflowService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class ApprovalController extends Controller
{
    public function __construct(private WorkflowService $workflow, private SlaService $sla)
    {
    }

    /**
     * Escalates any of this approver's own assignments whose SLA window
     * has already lapsed but haven't been picked up by the periodic
     * `workflow:check-parallel-slas` sweep yet. Called on-demand (page
     * load, decide attempt) so a stale cron interval can never leave an
     * expired assignment sitting in the approver's actionable queue —
     * the Clock-Stop Mechanism (Section 4) needs to take effect the
     * moment the deadline passes, not on whatever the next tick happens
     * to be.
     */
    private function escalateExpiredFor(int $userId): void
    {
        DocumentAssignment::where('user_id', $userId)
            ->where('individual_status', 'pending')
            ->where('escalated_to_admin', false)
            ->where('sla_expires_at', '<', now())
            ->with(['stage', 'document', 'approver'])
            ->get()
            ->each(fn (DocumentAssignment $a) => $this->sla->escalate($a));
    }

    /**
     * The set of priority labels ("Urgent"/"Normal"/"Low"/"Expired")
     * present across a container's document(s) — mirrors the exact same
     * per-document computation the Blade view uses for its badge (see
     * resources/views/approver/dashboard.blade.php), so the priority
     * filter matches what's actually displayed.
     */
    private function containerPriorityLabels($container): \Illuminate\Support\Collection
    {
        $priorityMap = [1 => 'Urgent', 2 => 'Normal', 3 => 'Low'];

        return $container->documents->map(function ($stageAssignments) use ($priorityMap) {
            $active = $stageAssignments->sortBy(fn (DocumentAssignment $a) => $a->stage->sequence_order)->first();
            $isExpired = $active->seconds_remaining !== null && $active->seconds_remaining <= 0;
            return $isExpired ? 'Expired' : ($priorityMap[$active->priority_rank] ?? $priorityMap[2]);
        })->unique()->values();
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
     *   - Inner: each document's own pending stage assignment(s) — since
     *     every configured stage is routed up front (not gated behind the
     *     prior stage's decision), a document can have more than one stage
     *     pending at once, and those still nest under that one document
     *     card rather than duplicating it.
     */
    /** Section 4: a breached assignment stays visible (disabled) in the approver's own queue for this long, so they see their own SLA misses instead of it silently vanishing. */
    private const BREACH_VISIBILITY_HOURS = 24;

    /**
     * Genuinely actionable (still within SLA) OR recently breached — the
     * latter stays visible read-only so the approver sees their own
     * misses instead of the item just disappearing the instant it
     * escalates. It drops off after BREACH_VISIBILITY_HOURS even if still
     * unresolved by Admin, so this queue never accumulates old breaches
     * forever; once Admin actually resolves it, individual_status stops
     * being 'pending' and it falls out of this query immediately
     * regardless of the time window. Shared by dashboard() (full data)
     * and poll() (just a count) so both always agree on what "pending"
     * means.
     */
    private function pendingQueryFor(int $userId)
    {
        return DocumentAssignment::where('user_id', $userId)
            ->where('individual_status', 'pending')
            ->where(function ($q) {
                $q->where('escalated_to_admin', false)
                    ->orWhere(function ($q2) {
                        $q2->where('escalated_to_admin', true)
                            ->whereNull('admin_override_at')
                            ->where('sla_expires_at', '>=', now()->subHours(self::BREACH_VISIBILITY_HOURS));
                    });
            });
    }

    /**
     * Builds the paginated, filtered container list both dashboard() (full
     * page) and refresh() (AJAX fragment for the live-polling swap) render
     * — kept in exactly one place so a live-swapped queue can never drift
     * from what a normal page load would have shown for the same filters.
     */
    private function buildQueue(Request $request, int $userId): array
    {
        $pending = $this->pendingQueryFor($userId)
            ->with(['document.batch', 'document.originator', 'document.assignments.approver', 'stage'])
            ->orderBy('priority_rank')
            ->orderBy('sla_expires_at')
            ->get();

        // Raw assignment count (same unit poll() returns), not the number
        // of grouped containers below — passed to the view as the polling
        // JS's starting baseline so "N new" comparisons are apples-to-apples.
        $initialPendingCount = $pending->count();

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

        if ($request->filled('priority')) {
            $wanted = $request->string('priority');
            $containers = $containers->filter(fn ($c) => $this->containerPriorityLabels($c)->contains($wanted))->values();
        }

        if ($request->filled('document')) {
            $term = mb_strtolower($request->string('document'));
            $containers = $containers->filter(function ($c) use ($term) {
                foreach ($c->documents as $stageAssignments) {
                    if (str_contains(mb_strtolower($stageAssignments->first()->document->title), $term)) {
                        return true;
                    }
                }
                return false;
            })->values();
        }

        $perPage = 10;
        $page = (int) $request->input('page', 1);

        $containers = new LengthAwarePaginator(
            $containers->forPage($page, $perPage)->values(),
            $containers->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return [$containers, $initialPendingCount];
    }

    public function dashboard(Request $request)
    {
        $this->escalateExpiredFor($request->user()->user_id);

        [$containers, $initialPendingCount] = $this->buildQueue($request, $request->user()->user_id);

        return view('approver.dashboard', compact('containers', 'initialPendingCount'));
    }

    /**
     * Renders just the queue fragment (resources/views/approver/partials/queue.blade.php)
     * for the dashboard's polling JS to swap into the page in place — see
     * dashboard.blade.php for why this is a smoother, less jarring update
     * than reloading the whole page. Respects the same priority/document
     * filters as a normal page load (the JS forwards the current query
     * string), so a live update never silently drops an active filter.
     */
    public function refresh(Request $request)
    {
        $this->escalateExpiredFor($request->user()->user_id);

        [$containers, $initialPendingCount] = $this->buildQueue($request, $request->user()->user_id);

        return view('approver.partials.queue', compact('containers', 'initialPendingCount'));
    }

    /**
     * Lightweight JSON endpoint the dashboard's JS polls every ~5-10s
     * (resources/views/approver/dashboard.blade.php) so a newly routed
     * document shows up without the approver having to manually refresh.
     * Deliberately just a count, not the full nested container payload
     * dashboard()/refresh() build — cheap enough to hit repeatedly; the
     * heavier refresh() fetch only happens when this actually detects a
     * change.
     */
    public function poll(Request $request)
    {
        $count = $this->pendingQueryFor($request->user()->user_id)->count();

        return response()->json(['pending_count' => $count]);
    }

    public function decide(Request $request, DocumentAssignment $assignment)
    {
        abort_unless($assignment->user_id === $request->user()->user_id, 403);

        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        abort_if($assignment->individual_status !== 'pending', 409, 'This assignment has already been actioned.');

        // Escalate right now if the deadline passed since this page was
        // loaded, rather than trust that the periodic sweep already caught
        // it — closes the window where a stale cron interval would let a
        // late decision through.
        if (!$assignment->escalated_to_admin && $assignment->sla_expires_at && now()->greaterThan($assignment->sla_expires_at)) {
            $this->sla->escalate($assignment);
        }
        abort_if($assignment->escalated_to_admin, 409, 'This assignment\'s SLA deadline has passed — it was just escalated to Admin and can no longer be decided here.');

        $this->workflow->decide($assignment, $request->user(), $validated['decision'], $validated['comments'] ?? null);

        return redirect()->route('approver.dashboard')->with('status', 'Decision recorded: ' . ucfirst($validated['decision']) . '.');
    }

    /**
     * Decides ALL of this approver's pending stages for one document in a
     * single action (Feature: one Approve/Reject button set per document,
     * not one per stage). Since every configured stage is routed up front,
     * the same approver can end up holding more than one stage for the
     * same document at once (e.g. stages 1 and 3, if they're the eligible
     * pick for both) — previously each showed its own Approve/Reject row,
     * which looked like duplicated buttons for what the approver sees as
     * one decision on one document. Rejecting any one stage already
     * cascades to close every other pending stage for the document (see
     * WorkflowService::completeStage()), so the loop below simply skips
     * any assignment that's no longer pending by the time it's reached.
     */
    public function decideBatch(Request $request)
    {
        $validated = $request->validate([
            'assignment_ids' => ['required', 'array', 'min:1'],
            'assignment_ids.*' => ['integer', 'exists:document_assignments,assignment_id'],
            'decision' => ['required', 'in:approved,rejected'],
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        $assignments = DocumentAssignment::whereIn('assignment_id', $validated['assignment_ids'])
            ->where('user_id', $request->user()->user_id)
            ->where('individual_status', 'pending')
            ->with(['stage', 'document', 'approver'])
            ->get();

        abort_if($assignments->isEmpty(), 409, 'These assignments have already been actioned.');

        $skippedExpired = 0;

        foreach ($assignments as $assignment) {
            $assignment->refresh();
            if ($assignment->individual_status !== 'pending') {
                continue; // already closed as a side effect of an earlier iteration (e.g. rejection cascade)
            }

            // Same on-demand escalation guard as decide() — don't let a
            // stale cron interval allow a late decision through.
            if (!$assignment->escalated_to_admin && $assignment->sla_expires_at && now()->greaterThan($assignment->sla_expires_at)) {
                $this->sla->escalate($assignment);
            }
            if ($assignment->escalated_to_admin) {
                $skippedExpired++;
                continue;
            }

            $this->workflow->decide($assignment, $request->user(), $validated['decision'], $validated['comments'] ?? null);
        }

        $status = 'Decision recorded: ' . ucfirst($validated['decision']) . '.';
        if ($skippedExpired > 0) {
            $status .= " {$skippedExpired} assignment(s) had already breached their SLA and were escalated to Admin instead.";
        }

        return redirect()->route('approver.dashboard')->with('status', $status);
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