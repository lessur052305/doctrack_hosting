@extends('layouts.app')
@section('title', 'SLA Violations')
@section('page-title', 'SLA Violation Reports')

@section('content')
<div class="space-y-6">

    {{-- Back to the folder grid — above the stat cards, first thing
         visible once you're inside a category. Styled as a filled pill
         rather than a bare link since it's now the top-most element on
         the page. --}}
    @unless($showFolders)
        <a href="{{ url()->current() }}" class="inline-flex items-center gap-1 text-sm font-medium text-primary-700 bg-primary-50 hover:bg-primary-100 ring-1 ring-inset ring-primary-500/20 rounded-full px-3 py-1.5 transition-colors">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            All Categories
        </a>
    @endunless

    {{-- Each card carries a stable id (see the script below) — clicking an
         approver's row further down swaps Total Violations/Most Violations/
         Top Bottleneck Stage/Disputed to THAT approver's own numbers. They
         stay showing that approver until a different one is clicked —
         closing the popup does NOT revert them (see selectApprover() below;
         there used to be a restore-on-close here, but that made the cards
         flip back to the category's own top offender — confusingly, always
         "Lessur Vinz" or whoever — the instant you closed the popup you'd
         just opened to look at).

         Two cards that used to live here were removed: "Avg. Minutes
         Overdue" (a real internal signal — how long it takes the system to
         NOTICE an expired deadline, not how long a document sits
         unapproved, since auto-approval is instant either way — but that
         distinction read as confusing/wrong to anyone without that context,
         so it's gone from this dashboard) and "Top Category" (this page
         only ever shows these cards once you're already inside one
         category's folder, so it could only ever repeat the folder you're
         already standing in). --}}
    @unless($showFolders)
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white rounded-xl shadow-card border border-surface-200 p-5">
                <p class="text-sm text-surface-500 mb-1">Total Violations</p>
                <p class="text-2xl font-bold text-rejected-700" id="stat-total-violations">{{ $totalCount }}</p>
            </div>
            <div class="bg-white rounded-xl shadow-card border border-surface-200 p-5">
                <p class="text-sm text-surface-500 mb-1" id="stat-top-approver-label">Most Violations</p>
                <p class="text-sm font-semibold text-surface-900" id="stat-top-approver-name">{{ optional($byApprover->first()?->approver)->full_name ?? '—' }}</p>
                <p class="text-sm text-surface-400" id="stat-top-approver-sub">{{ $byApprover->first()->total ?? 0 }} violation(s)</p>
            </div>
            <div class="bg-white rounded-xl shadow-card border border-surface-200 p-5">
                <p class="text-sm text-surface-500 mb-1">Top Bottleneck Stage</p>
                <p class="text-sm font-semibold text-surface-900" id="stat-top-stage-name">{{ $byStage->first()->stage_name ?? '—' }}</p>
                <p class="text-sm text-surface-400" id="stat-top-stage-sub">{{ $byStage->first()->total ?? 0 }} violation(s)</p>
            </div>
            <div class="bg-white rounded-xl shadow-card border border-surface-200 p-5">
                <p class="text-sm text-surface-500 mb-1">Disputed</p>
                <p class="text-2xl font-bold text-processing-700" id="stat-disputed">{{ $disputedCount }}</p>
            </div>
        </div>
    @endunless

    {{-- Admin Violations + Approvers side by side on wider screens (there's
         plenty of horizontal room once the old search/results list is
         gone), stacking back to full-width below lg so neither table gets
         cramped on a narrower screen. --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
        {{-- Admin Violations — only inside a category folder, scoped to
             just that category (see AdminController::adminViolationsData());
             never shown on the bare landing screen and never mixes in
             another category's violations. Always visible (not collapsed)
             — see admin-violations-results.blade.php for the per-document
             layout. --}}
        @if(request()->filled('category'))
        <div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
            <h2 class="px-6 py-4 text-sm font-semibold text-surface-900 border-b border-surface-200">Admin Violations</h2>

            <div id="admin-violations-results"
                data-refresh-url="{{ route('admin.sla.violations.admin.refresh', ['category' => request('category')]) }}"
                data-poll-url="{{ route('admin.sla.violations.admin.poll', ['category' => request('category')]) }}">
                @include('admin.partials.admin-violations-results')
            </div>
        </div>
        @endif

        {{-- Approvers — always visible (not collapsed), gated behind a
             category pick same as the stat cards above. Each row opens the
             shared KPI drill-down popup (see components/kpi-drilldown-modal.
             blade.php) showing that approver's own violated documents +
             the stage(s) each happened on, AND swaps the top cards above
             to that approver's own numbers (see the script below). --}}
        @unless($showFolders)
        <div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-surface-200">
                <h2 class="text-sm font-semibold text-surface-900 mb-3">Approvers — Violation Counts</h2>
                <input type="text" id="approver-roster-search" placeholder="Search approver…" autocomplete="off"
                    class="w-full max-w-sm rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
            </div>

            <ul id="approver-roster-list" class="divide-y divide-surface-100">
                @forelse($approverRoster as $approver)
                    <li data-approver-name="{{ strtolower($approver->full_name) }}">
                        <button type="button"
                            class="w-full flex items-center justify-between gap-3 px-6 py-3 text-sm text-left {{ $approver->violation_count > 0 ? 'hover:bg-surface-50/60 cursor-pointer' : 'cursor-default' }}"
                            @if($approver->violation_count > 0)
                                onclick="selectApprover(
                                    '{{ addslashes($approver->full_name) }}',
                                    '{{ route('admin.sla.violations.approver', ['approver' => $approver->user_id, 'category' => request('category')]) }}',
                                    '{{ route('admin.sla.violations.approver.stats', ['approver' => $approver->user_id, 'category' => request('category')]) }}'
                                )"
                            @endif
                        >
                            <div class="min-w-0">
                                <p class="font-medium text-surface-800 truncate">{{ $approver->full_name }}</p>
                                <p class="text-surface-400">{{ $approver->assigned_category ?? '—' }}</p>
                            </div>
                            <div class="text-right shrink-0">
                                <p class="font-semibold {{ $approver->violation_count > 0 ? 'text-rejected-700' : 'text-approved-700' }}">{{ $approver->violation_count }} violation{{ $approver->violation_count === 1 ? '' : 's' }}</p>
                                <p class="text-surface-400">of {{ $approver->assignment_count }} assigned</p>
                            </div>
                        </button>
                    </li>
                @empty
                    <li class="px-6 py-4 text-center text-surface-400">No approvers found.</li>
                @endforelse
            </ul>
            <p id="approver-roster-empty" class="hidden py-4 text-center text-sm text-surface-400">No approver matches your search.</p>
        </div>
        @endunless
    </div>

    @if($showFolders)
        {{-- Folders only — the Admin/Approver tables only appear once
             you've picked a category, same pattern as the Document
             Archive. --}}
        <h2 class="text-sm font-semibold text-surface-900 mb-3">Browse by Category</h2>
        {{-- Feature: bigger folders that actually fill the screen — same
             treatment as resources/views/archive/index.blade.php's
             identical folder markup; see that file for why 2 fixed
             columns + a taller body instead of the old responsive
             2/3/4-column, h-32 pairing. --}}
        <div class="grid grid-cols-2 gap-8">
            @foreach($folders as $folder)
                <a href="{{ url()->current() }}?category={{ urlencode($folder->category) }}" class="group block">
                    <div class="w-40 h-10 ml-8 rounded-t-lg bg-gradient-to-br from-primary-300 to-primary-500 group-hover:from-primary-400 group-hover:to-primary-600 transition-colors"></div>
                    <div class="-mt-px h-64 rounded-b-xl rounded-tr-xl bg-gradient-to-br from-primary-400 to-primary-600 group-hover:from-primary-500 group-hover:to-primary-700 shadow-lg group-hover:shadow-xl group-hover:-translate-y-0.5 transition-all flex flex-col items-center justify-center text-center px-4">
                        <h3 class="text-xl font-semibold text-white drop-shadow-sm">{{ $folder->category }}</h3>
                        <p class="text-base text-primary-100 mt-1">{{ $folder->total }} violation{{ $folder->total === 1 ? '' : 's' }}</p>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Client-side name filter for the always-visible Approvers table.
        document.getElementById('approver-roster-search')?.addEventListener('input', function (e) {
            const term = e.target.value.trim().toLowerCase();
            const rows = document.querySelectorAll('#approver-roster-list [data-approver-name]');
            let visibleCount = 0;

            rows.forEach((row) => {
                const matches = row.dataset.approverName.includes(term);
                row.classList.toggle('hidden', !matches);
                if (matches) visibleCount++;
            });

            document.getElementById('approver-roster-empty')?.classList.toggle('hidden', visibleCount !== 0);
        });

        // The admin-side violations panel has its own independent
        // live-refresh, present only once a category is picked (see the
        // request()->filled('category') guard around it further up).
        const adminResultsEl = document.getElementById('admin-violations-results');
        if (adminResultsEl) {
            const adminOpts = {
                refreshUrl: adminResultsEl.dataset.refreshUrl,
                target: adminResultsEl,
            };
            startLiveChannel('admin-dashboard', '.admin.activity-logged', adminOpts);
            startLivePoll({ ...adminOpts, pollUrl: adminResultsEl.dataset.pollUrl });
        }

        // Reusable stat cards (Feature: clicking an approver's row below
        // replaces the top cards with THEIR numbers instead of the
        // category-wide ones). Deliberately no revert-on-close — the
        // cards just stay on whichever approver was last selected until
        // another row is clicked; closing the popup only closes the popup.
        window.selectApprover = function (approverName, popupUrl, statsUrl) {
            openKpiDrilldown('approver', `${approverName} — Violated Documents`, popupUrl);

            fetch(statsUrl, { headers: { Accept: 'application/json' } })
                .then((res) => (res.ok ? res.json() : Promise.reject()))
                .then((data) => {
                    document.getElementById('stat-total-violations').textContent = data.totalCount;
                    document.getElementById('stat-top-approver-label').textContent = 'Selected Approver';
                    document.getElementById('stat-top-approver-name').textContent = data.name;
                    document.getElementById('stat-top-approver-sub').textContent = data.rank
                        ? `#${data.rank} of ${data.rosterCount} approvers`
                        : `of ${data.rosterCount} approvers`;
                    document.getElementById('stat-top-stage-name').textContent = data.topStageName;
                    document.getElementById('stat-top-stage-sub').textContent = `${data.topStageTotal} violation(s)`;
                    document.getElementById('stat-disputed').textContent = data.disputedCount;
                })
                .catch(() => {});
        };
    });
</script>
@endsection
