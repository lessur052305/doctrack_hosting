@extends('layouts.app')
@section('title', 'Auto-Approval Review')
@section('page-title', 'Auto-Approval Review')

@section('content')
<div class="space-y-6">
    <div class="rounded-lg bg-processing-50 border border-processing-500/30 text-processing-700 px-4 py-3 text-xs">
        Every missed deadline auto-approves right away — a real approver's own miss, or a stage with no eligible approver at all (auto-approved immediately, no waiting). The documents below still need your final review, just after the fact instead of before.
    </div>

    <div id="sla-queue-results" class="space-y-6" data-poll-url="{{ route('admin.sla.queue.poll') }}" data-refresh-url="{{ route('admin.sla.queue.refresh') }}">
        @include('admin.partials.sla-queue-results')
    </div>
</div>

<script>
    // Same live-poll pattern as every other admin module — see
    // dashboard.blade.php's comment for the full reasoning. A new
    // auto-approval landing here, or another admin reviewing one, needs
    // to show up without a manual reload.
    document.addEventListener('DOMContentLoaded', function () {
        const resultsEl = document.getElementById('sla-queue-results');
        if (!resultsEl) return;

        const opts = {
            refreshUrl: resultsEl.dataset.refreshUrl,
            target: resultsEl,
            preserveQueryString: true, // keeps whichever page of the queue is currently open
        };

        startLiveChannel('admin-dashboard', '.admin.activity-logged', opts);
        startLivePoll({ ...opts, pollUrl: resultsEl.dataset.pollUrl });

        enableAjaxPagination(resultsEl, opts);

        // Deep-link from Admin Violations (?highlight={document_id}) —
        // slaQueueData() already made sure the right PAGE loaded; this
        // just scrolls to and briefly rings the specific card on it.
        const highlightId = new URLSearchParams(window.location.search).get('highlight');
        if (highlightId) {
            const target = document.getElementById(`review-doc-${highlightId}`);
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                target.classList.add('ring-2', 'ring-primary-500');
                setTimeout(() => target.classList.remove('ring-2', 'ring-primary-500'), 2000);
            }
        }
    });
</script>
@endsection
