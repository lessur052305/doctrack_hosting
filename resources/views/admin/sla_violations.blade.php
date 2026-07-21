@extends('layouts.app')
@section('title', 'SLA Violations')
@section('page-title', 'SLA Violation Reports')

@section('content')
<div class="space-y-6">

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-5">
            <p class="text-xs text-surface-500 mb-1">Total Violations</p>
            <p class="text-2xl font-bold text-rejected-700">{{ $totalCount }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-5">
            <p class="text-xs text-surface-500 mb-1">Avg. Minutes Overdue</p>
            <p class="text-2xl font-bold text-surface-900">{{ $avgOverdue }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-5">
            <p class="text-xs text-surface-500 mb-1">Top Approver</p>
            <p class="text-sm font-semibold text-surface-900">{{ optional($byApprover->first()?->approver)->full_name ?? '—' }}</p>
            <p class="text-xs text-surface-400">{{ $byApprover->first()->total ?? 0 }} breach(es)</p>
        </div>
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-5">
            <p class="text-xs text-surface-500 mb-1">Top Bottleneck Stage</p>
            <p class="text-sm font-semibold text-surface-900">{{ $byStage->first()->stage_name ?? '—' }}</p>
            <p class="text-xs text-surface-400">{{ $byStage->first()->total ?? 0 }} breach(es)</p>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
        <form method="GET" class="px-6 py-4 border-b border-surface-200 space-y-3">
            <div class="relative">
                <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-surface-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
                </svg>
                <input type="text" id="document-search" name="document" value="{{ request('document') }}"
                    placeholder="Search document"
                    autocomplete="off"
                    class="w-full rounded-lg border-surface-300 text-sm pl-9 pr-3 py-2.5 focus:border-primary-500 focus:ring-primary-500">
            </div>
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-[11px] font-medium text-surface-500 mb-1">Category</label>
                    <select name="category" class="rounded-lg border-surface-300 text-xs px-3 py-2">
                        <option value="">All Categories</option>
                        @foreach($categories as $c)
                            <option value="{{ $c }}" {{ request('category') === $c ? 'selected' : '' }}>{{ $c }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-medium text-surface-500 mb-1">From</label>
                    <input type="date" name="date_from" value="{{ request('date_from') }}" class="rounded-lg border-surface-300 text-xs px-3 py-2">
                </div>
                <div>
                    <label class="block text-[11px] font-medium text-surface-500 mb-1">To</label>
                    <input type="date" name="date_to" value="{{ request('date_to') }}" class="rounded-lg border-surface-300 text-xs px-3 py-2">
                </div>
                <button class="text-xs font-medium bg-primary-700 hover:bg-primary-800 text-white px-4 py-2.5 rounded-lg">Filter</button>
                <a href="{{ route('admin.sla.violations') }}" class="text-xs font-medium text-surface-500 hover:underline pb-2.5">Clear</a>
            </div>
        </form>

        <details class="border-b border-surface-200 group [&_summary::-webkit-details-marker]:hidden">
            <summary class="px-6 py-3 cursor-pointer select-none text-xs font-medium text-primary-700 hover:bg-surface-50 flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5 transition-transform group-open:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                View All Approvers &amp; Breach Counts
            </summary>

            <div class="px-6 py-4 bg-surface-50/50 border-t border-surface-200">
                <input type="text" id="approver-roster-search" placeholder="Search approver…" autocomplete="off"
                    class="w-full max-w-sm rounded-lg border-surface-300 text-xs mb-3 px-3 py-2 focus:border-primary-500 focus:ring-primary-500">

                <ul id="approver-roster-list" class="max-h-80 overflow-y-auto divide-y divide-surface-100 bg-white rounded-lg border border-surface-200">
                    @forelse($approverRoster as $approver)
                        <li data-approver-name="{{ strtolower($approver->full_name) }}">
                            <details class="group">
                                <summary class="list-none [&::-webkit-details-marker]:hidden cursor-pointer flex items-center justify-between gap-3 px-4 py-2.5 text-xs hover:bg-surface-50/60">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <svg class="w-3 h-3 text-surface-400 shrink-0 transition-transform group-open:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                        <div class="min-w-0">
                                            <p class="font-medium text-surface-800 truncate">{{ $approver->full_name }}</p>
                                            <p class="text-surface-400">{{ $approver->assigned_category ?? '—' }}</p>
                                        </div>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <p class="font-semibold {{ $approver->breach_count > 0 ? 'text-rejected-700' : 'text-approved-700' }}">{{ $approver->breach_count }} breach{{ $approver->breach_count === 1 ? '' : 'es' }}</p>
                                        <p class="text-surface-400">of {{ $approver->assignment_count }} assigned</p>
                                    </div>
                                </summary>
                                <div class="px-4 pb-3 pl-9 bg-surface-50/50 border-t border-surface-100">
                                    @php $categoryBreakdown = $byApproverCategory->get($approver->user_id, collect()); @endphp
                                    @forelse($categoryBreakdown as $row)
                                        <p class="text-[11px] text-surface-600 flex items-center justify-between py-1 border-b border-surface-100 last:border-0">
                                            <span>{{ $row->ml_category }}</span>
                                            <span class="font-semibold text-rejected-700">{{ $row->total }} breach{{ $row->total === 1 ? '' : 'es' }}</span>
                                        </p>
                                    @empty
                                        <p class="text-[11px] text-surface-400 py-1">No breaches recorded.</p>
                                    @endforelse
                                </div>
                            </details>
                        </li>
                    @empty
                        <li class="px-4 py-4 text-center text-surface-400">No approvers found.</li>
                    @endforelse
                </ul>
                <p id="approver-roster-empty" class="hidden py-4 text-center text-xs text-surface-400">No approver matches your search.</p>
            </div>

            <script>
                document.getElementById('approver-roster-search')?.addEventListener('input', function (e) {
                    const term = e.target.value.trim().toLowerCase();
                    const rows = document.querySelectorAll('#approver-roster-list [data-approver-name]');
                    let visibleCount = 0;

                    rows.forEach((row) => {
                        const matches = row.dataset.approverName.includes(term);
                        row.classList.toggle('hidden', !matches);
                        if (matches) visibleCount++;
                    });

                    document.getElementById('approver-roster-empty').classList.toggle('hidden', visibleCount !== 0);
                });
            </script>
        </details>

        @php
            // Nest by document — a document can rack up several breaches
            // (one per stage, or repeat breaches over time), which
            // previously showed as separate flat rows repeating the same
            // title and made the report noisy. Same pattern as the Admin
            // dashboard's "SLA Override Alerts" widget.
            $violationsByDocument = $violations->getCollection()->groupBy('document_id');
        @endphp

        <div id="violation-groups" class="divide-y divide-surface-100">
            @forelse($violationsByDocument as $docViolations)
                @php
                    $doc = $docViolations->first()->document;
                    $latest = $docViolations->first(); // already ordered newest-first from the query
                    $count = $docViolations->count();
                @endphp
                <details class="violation-group group" data-document-title="{{ strtolower($doc->title ?? '') }}">
                    <summary class="list-none [&::-webkit-details-marker]:hidden cursor-pointer px-6 py-4 hover:bg-surface-50/60 flex items-center justify-between gap-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <svg class="w-4 h-4 text-surface-400 shrink-0 transition-transform group-open:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                            </svg>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-surface-900 truncate">{{ $doc->title ?? 'Deleted document' }}</p>
                                <p class="text-xs text-surface-400 truncate">
                                    {{ $doc?->ml_category }}
                                    @if($doc?->upload_date) · Uploaded {{ $doc->upload_date->format('M j, Y g:i A') }}@endif
                                    @if($doc?->due_date)
                                        · Due {{ $doc->due_date->format('M j, Y g:i A') }}
                                        @if($doc?->upload_date)
                                            <span class="{{ $doc->upload_date->diffInMinutes($doc->due_date) <= 60 ? 'text-rejected-500 font-medium' : '' }}">({{ $doc->upload_date->diffForHumans($doc->due_date, true) }} turnaround)</span>
                                        @endif
                                    @endif
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 shrink-0">
                            <span class="hidden sm:inline text-xs text-surface-400">Latest
                                <span data-live-time="{{ $latest->violation_timestamp->timestamp }}">{{ $latest->violation_timestamp->diffForHumans() }}</span>
                            </span>
                            @if($doc)
                                {{-- Status of the document RIGHT NOW, not at breach time — a
                                     violation here is a historical log entry, so the document
                                     may well already be resolved since. This is the single most
                                     useful signal for triage: still needs attention vs. handled. --}}
                                <x-status-badge :status="$doc->display_status" />
                            @endif
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rejected-50 text-rejected-700 ring-1 ring-inset ring-rejected-500/20">
                                {{ $count }} breach{{ $count === 1 ? '' : 'es' }}
                            </span>
                        </div>
                    </summary>

                    <div class="px-6 pb-4">
                        <div class="hidden sm:grid grid-cols-[minmax(150px,170px)_1fr_1fr_minmax(150px,170px)] gap-4 px-3 py-1.5 text-surface-400 text-[11px] font-semibold uppercase tracking-wide">
                            <span>Breached</span>
                            <span>Stage</span>
                            <span>Approver</span>
                            <span>Resolved</span>
                        </div>
                        <div class="space-y-1">
                            @foreach($docViolations as $v)
                                @php
                                    // The exact deadline that was crossed (sla_expires_at) is more
                                    // precise than violation_timestamp, which only records when the
                                    // periodic sweep happened to notice — fall back to it for
                                    // violations logged before assignment_id was linked.
                                    $breachedAt = $v->assignment->sla_expires_at ?? $v->violation_timestamp;
                                    $resolvedStatus = $v->assignment->individual_status ?? null;
                                @endphp
                                <div class="grid grid-cols-2 sm:grid-cols-[minmax(150px,170px)_1fr_1fr_minmax(150px,170px)] gap-x-4 gap-y-0.5 text-xs py-2 px-3 rounded-lg odd:bg-surface-50/50">
                                    <span class="col-span-2 sm:col-span-1 text-surface-500 whitespace-nowrap">{{ $breachedAt->format('M j, Y g:i A') }}</span>
                                    <span class="text-surface-700 font-medium truncate">{{ $v->stage_name }}</span>
                                    <span class="text-surface-600 truncate">{{ $v->approver->full_name ?? 'Unassigned' }}</span>
                                    <span class="col-span-2 sm:col-span-1">
                                        @if($resolvedStatus === null)
                                            <span class="text-surface-400">—</span>
                                        @elseif($resolvedStatus === 'pending')
                                            <span class="text-processing-700 font-medium">Still pending</span>
                                        @else
                                            @php
                                                $a = $v->assignment;
                                                // Once escalated, the original approver can no longer
                                                // act (see ApprovalController's 409 guard) — so a
                                                // resolved breach was always either an Admin override
                                                // or the system's own grace-window auto-approval.
                                                $resolvedBy = $a->auto_approved
                                                    ? 'Auto-approved by system'
                                                    : ($a->admin_override_by ? 'Admin override: ' . ($a->adminOverrideBy->full_name ?? 'Admin') : ($a->approver->full_name ?? 'Unknown'));
                                            @endphp
                                            <span class="{{ $resolvedStatus === 'rejected' ? 'text-rejected-700' : 'text-approved-700' }} font-medium whitespace-nowrap block">
                                                {{ $a->acted_at?->format('M j, Y g:i A') ?? '—' }}
                                            </span>
                                            <span class="text-surface-400 text-[11px]">{{ $resolvedBy }}</span>
                                        @endif
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </details>
            @empty
                <div class="px-6 py-8 text-center text-surface-400 text-sm">No SLA violations match these filters.</div>
            @endforelse
            <div id="violation-no-matches" class="hidden px-6 py-8 text-center text-surface-400 text-sm">
                No documents on this page match "<span id="violation-no-matches-term"></span>". Press Enter to search every page.
            </div>
        </div>
        <div class="px-6 py-4 border-t border-surface-200">{{ $violations->links() }}</div>
    </div>
</div>

<script>
    // Real-time, client-side filter over the document groups already
    // rendered on this page — instant, no round trip. Pressing Enter still
    // submits the surrounding <form> normally, running the "document"
    // filter server-side (see AdminController::violationsReport()) across
    // every page, not just what's currently visible.
    (function () {
        const input = document.getElementById('document-search');
        const groups = Array.from(document.querySelectorAll('.violation-group'));
        const noMatches = document.getElementById('violation-no-matches');
        const noMatchesTerm = document.getElementById('violation-no-matches-term');
        if (!input || groups.length === 0) return;

        input.addEventListener('input', () => {
            const term = input.value.trim().toLowerCase();
            let visibleCount = 0;

            groups.forEach((group) => {
                const matches = term === '' || group.dataset.documentTitle.includes(term);
                group.classList.toggle('hidden', !matches);
                if (matches) visibleCount++;
            });

            const showNoMatches = term !== '' && visibleCount === 0;
            noMatches.classList.toggle('hidden', !showNoMatches);
            if (showNoMatches) noMatchesTerm.textContent = input.value.trim();
        });
    })();
</script>
@endsection
