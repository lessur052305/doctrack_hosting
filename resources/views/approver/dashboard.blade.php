@extends('layouts.app')
@section('title', 'Review Queue')
@section('page-title', 'Review Queue')

@section('content')
<div class="space-y-6">
    <div class="bg-white rounded-xl shadow-card border border-surface-200 p-4">
        <form method="GET" class="flex flex-wrap items-center gap-3">
            <div class="relative flex-1 min-w-[200px]">
                <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-surface-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
                </svg>
                <input type="text" id="document-search" name="document" value="{{ request('document') }}"
                    placeholder="Search document" autocomplete="off"
                    class="w-full rounded-lg border-surface-300 text-sm pl-9 pr-3 py-2 focus:border-primary-500 focus:ring-primary-500">
            </div>
            <select name="priority" onchange="this.form.submit()" class="rounded-lg border-surface-300 text-xs px-3 py-2">
                <option value="">All Priorities</option>
                @foreach(['Urgent', 'Normal', 'Low', 'Expired'] as $p)
                    <option value="{{ $p }}" {{ request('priority') === $p ? 'selected' : '' }}>{{ $p }}</option>
                @endforeach
            </select>
            <button class="text-xs font-medium bg-primary-700 hover:bg-primary-800 text-white px-4 py-2 rounded-lg">Filter</button>
            @if(request('document') || request('priority'))
                <a href="{{ route('approver.dashboard') }}" class="text-xs font-medium text-surface-500 hover:underline">Clear</a>
            @endif
        </form>
    </div>

    {{-- Tiny, auto-fading acknowledgment that a live update just happened —
         intentionally not a banner requiring a click; see the JS below for
         why (feels live, not "obviously refreshed"). Deliberately generic
         copy, not "N new" — this fires from two different triggers (an
         instant WebSocket push or the slow fallback poll) whose payloads
         don't share the same shape, so there isn't always a reliable count
         to report. --}}
    <div id="live-update-toast" class="hidden text-xs text-approved-700 font-medium transition-opacity duration-700">
        ✓ Queue updated
    </div>

    <div id="review-queue" class="space-y-6" data-user-id="{{ auth()->id() }}" data-poll-url="{{ route('approver.assignments.poll') }}" data-refresh-url="{{ route('approver.assignments.refresh') }}" data-initial-count="{{ $initialPendingCount }}">
        @include('approver.partials.queue')
    </div>
</div>

<script>
    // Real-time, client-side filter over the containers already rendered on
    // this page — instant, no round trip. Pressing Enter still submits the
    // surrounding <form> normally, running the "document" filter
    // server-side (see ApprovalController::dashboard()) across every page.
    // Re-run after every live swap too (see below), since fresh markup
    // needs the same filter re-applied — otherwise a swap would silently
    // undo an active search.
    function applyDocumentFilter() {
        const input = document.getElementById('document-search');
        const containers = Array.from(document.querySelectorAll('.review-container'));
        const noMatches = document.getElementById('review-no-matches');
        const noMatchesTerm = document.getElementById('review-no-matches-term');
        if (!input || containers.length === 0 || !noMatches) return;

        const term = input.value.trim().toLowerCase();
        let visibleCount = 0;

        containers.forEach((el) => {
            const matches = term === '' || el.dataset.documentTitles.includes(term);
            el.classList.toggle('hidden', !matches);
            if (matches) visibleCount++;
        });

        const showNoMatches = term !== '' && visibleCount === 0;
        noMatches.classList.toggle('hidden', !showNoMatches);
        if (showNoMatches) noMatchesTerm.textContent = input.value.trim();
    }

    document.getElementById('document-search')?.addEventListener('input', applyDocumentFilter);

    // Live-updates the queue without a full page reload — instant via
    // Reverb (see startLiveChannel in resources/js/app.js) when a new
    // assignment is routed to this approver; the slow poll behind it is
    // only a fallback in case the WebSocket connection is down.
    //
    // Wrapped in DOMContentLoaded, not a bare IIFE: this is a plain inline
    // script, which runs immediately as the browser parses this point in
    // the page — but app.js (which defines startLiveChannel/startLivePoll)
    // is loaded via the Vite-injected module script tag in the layout's
    // <head>, and module scripts are always deferred until after the page
    // finishes parsing. Without this wrapper, this code runs BEFORE those
    // functions exist and silently throws, and nothing below the throw
    // ever executes — which is exactly why live updates looked completely
    // wired up but never actually ran.
    document.addEventListener('DOMContentLoaded', function () {
        const queueEl = document.getElementById('review-queue');
        if (!queueEl) return;

        const toast = document.getElementById('live-update-toast');

        const showToast = () => {
            toast.classList.remove('hidden');
            toast.style.opacity = '1';
            setTimeout(() => { toast.style.opacity = '0'; }, 2500);
            setTimeout(() => { toast.classList.add('hidden'); }, 3200);
        };

        const opts = {
            refreshUrl: queueEl.dataset.refreshUrl,
            target: queueEl,
            preserveQueryString: true,
            // If the approver is actively typing a comment (a textarea has
            // focus or unsaved text), skip this update rather than wiping
            // out whatever they were about to submit — the next poll cycle
            // (or the approver's own next action) will catch it up.
            isBusy: () => Array.from(document.querySelectorAll('#review-queue textarea')).some(
                (el) => el === document.activeElement || el.value.trim() !== ''
            ),
            onSwap: () => {
                applyDocumentFilter();
                showToast();
            },
        };

        startLiveChannel(`approver.${queueEl.dataset.userId}`, '.assignment.routed', opts);
        startLivePoll({ ...opts, pollUrl: queueEl.dataset.pollUrl });
    });
</script>
@endsection
