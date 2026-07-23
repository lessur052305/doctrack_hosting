@extends('layouts.app')
@section('title', 'SLA Violations')
@section('page-title', 'SLA Violation Reports')

@section('content')
<div class="space-y-6">

    <div class="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
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
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-5">
            <p class="text-xs text-surface-500 mb-1">Top Category</p>
            <p class="text-sm font-semibold text-surface-900">{{ $byCategory->ml_category ?? '—' }}</p>
            <p class="text-xs text-surface-400">{{ $byCategory->total ?? 0 }} breach(es)</p>
        </div>
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-5">
            <p class="text-xs text-surface-500 mb-1">Disputed</p>
            <p class="text-2xl font-bold text-processing-700">{{ $disputedCount }}</p>
        </div>
    </div>

    {{-- Full-width and right under the stat cards on purpose — this used to
         be a small text link buried under a filter form that isn't even
         visible by default anymore, easy to miss entirely. --}}
    <details class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden group [&_summary::-webkit-details-marker]:hidden">
        <summary class="px-6 py-4 cursor-pointer select-none text-sm font-semibold text-primary-700 hover:bg-surface-50 flex items-center gap-2">
            <svg class="w-4 h-4 transition-transform group-open:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
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

    @if($showFolders)
        {{-- Folders only — the search/filter panel and results list only
             appear once you've picked a category (or searched), same
             pattern as the Document Archive. --}}
        <h2 class="text-sm font-semibold text-surface-900 mb-3">Browse by Category</h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-6">
            @foreach($folders as $folder)
                <a href="{{ url()->current() }}?category={{ urlencode($folder->category) }}" class="group block">
                    <div class="w-24 h-6 ml-5 rounded-t-lg bg-gradient-to-br from-primary-300 to-primary-500 group-hover:from-primary-400 group-hover:to-primary-600 transition-colors"></div>
                    <div class="-mt-px h-32 rounded-b-xl rounded-tr-xl bg-gradient-to-br from-primary-400 to-primary-600 group-hover:from-primary-500 group-hover:to-primary-700 shadow-lg group-hover:shadow-xl group-hover:-translate-y-0.5 transition-all flex flex-col items-center justify-center text-center px-4">
                        <h3 class="text-sm font-semibold text-white drop-shadow-sm">{{ $folder->category }}</h3>
                        <p class="text-xs text-primary-100 mt-0.5">{{ $folder->total }} breach{{ $folder->total === 1 ? '' : 'es' }}</p>
                    </div>
                </a>
            @endforeach
        </div>
    @else
        <div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-surface-200 space-y-3">
                <a href="{{ url()->current() }}" class="inline-flex items-center gap-1 text-xs font-medium text-primary-700 hover:underline">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    All Categories
                </a>
                <form method="GET" id="violations-filter-form" class="space-y-3">
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
                        {{-- Fixed, not a dropdown — the only way into this
                             view is by clicking a category folder, so it's
                             never actually a free choice here; a <select>
                             would just imply you can jump categories without
                             going back. Still submitted via a hidden input
                             so search/live-fetch keep the category filter. --}}
                        <input type="hidden" name="category" value="{{ request('category') }}">
                        <div>
                            <label class="block text-[11px] font-medium text-surface-500 mb-1">Category</label>
                            <span class="inline-flex items-center px-3 py-2 rounded-lg bg-surface-100 text-xs font-medium text-surface-700">{{ request('category') }}</span>
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
                        <a href="{{ url()->current() }}" class="text-xs font-medium text-surface-500 hover:underline pb-2.5">Clear</a>
                    </div>
                </form>
            </div>

            <div id="violations-results" data-refresh-url="{{ route('admin.sla.violations.refresh') }}">
                @include('admin.partials.violations_results')
            </div>
        </div>
    @endif
</div>

<script>
    // Live search (Feature: instant results as you type, no page reload) —
    // same debounced-fetch pattern as the Document Archive
    // (resources/views/archive/index.blade.php) — see that file's comment
    // for the full reasoning. Keyword is debounced; category/date fire
    // immediately on change. The <form>/Filter button remain a working
    // no-JS fallback.
    (function () {
        const resultsEl = document.getElementById('violations-results');
        if (!resultsEl) return;

        const form = document.getElementById('violations-filter-form');
        const documentInput = document.getElementById('document-search');
        const refreshUrl = resultsEl.dataset.refreshUrl;
        let debounceTimer = null;
        let currentRequest = null;

        const runSearch = () => {
            const params = new URLSearchParams(new FormData(form));
            Array.from(params.keys()).forEach((key) => {
                if (params.get(key) === '') params.delete(key);
            });

            if (currentRequest) currentRequest.abort();
            currentRequest = new AbortController();

            fetch(`${refreshUrl}?${params.toString()}`, {
                headers: { Accept: 'text/html' },
                signal: currentRequest.signal,
            })
                .then((res) => (res.ok ? res.text() : Promise.reject(res)))
                .then((html) => {
                    resultsEl.innerHTML = html;
                    const query = params.toString();
                    history.replaceState(null, '', query ? `${window.location.pathname}?${query}` : window.location.pathname);
                })
                .catch(() => {});
        };

        if (documentInput) {
            documentInput.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(runSearch, 300);
            });
        }

        form.querySelectorAll('select[name="category"], input[name="date_from"], input[name="date_to"]')
            .forEach((el) => el.addEventListener('change', runSearch));

        // Pagination links inside the swapped-in fragment point at the full
        // page — intercept and fetch the same query string from the
        // refresh endpoint instead, so paging stays live too.
        resultsEl.addEventListener('click', (e) => {
            const link = e.target.closest('a[href]');
            if (!link || !resultsEl.contains(link)) return;
            const url = new URL(link.href, window.location.origin);
            if (url.pathname !== window.location.pathname) return;
            e.preventDefault();
            fetch(`${refreshUrl}?${url.searchParams.toString()}`, { headers: { Accept: 'text/html' } })
                .then((res) => (res.ok ? res.text() : Promise.reject(res)))
                .then((html) => {
                    resultsEl.innerHTML = html;
                    history.replaceState(null, '', link.href);
                })
                .catch(() => {});
        });
    })();
</script>
@endsection
