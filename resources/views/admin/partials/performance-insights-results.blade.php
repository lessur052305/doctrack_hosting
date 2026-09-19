{{--
    Performance Insights results — split out from performance_insights.blade.php
    so the same markup can be rendered two ways: a normal full page load, and
    a fragment returned by AdminController::performanceInsightsRefresh() for
    the live-poll JS to swap in place, without a full page reload.

    Three plain historical rankings, side by side — see
    PerformanceInsightsService's docblock for why none of this is ML.

    Feature: a Fastest/Slowest toggle — both directions are rendered here
    (one hidden via the `hidden` class), swapped by the plain JS toggle in
    performance_insights.blade.php. Rendering both server-side rather than
    fetching "slowest" on click keeps the toggle instant (no request) and
    keeps this fragment self-contained for the live-poll swap.
--}}
@php
    $modes = [
        'fastest' => [
            ['title' => 'Fastest Approvers', 'subtitle' => 'Ranked by average time from assignment to decision.', 'rows' => $fastestApprovers, 'empty' => 'Not enough decision history yet to rank approvers.'],
            ['title' => 'Fastest Departments', 'subtitle' => 'Same ranking, grouped by department.', 'rows' => $fastestDepartments, 'empty' => 'Not enough decision history yet to rank departments.'],
            ['title' => 'Fastest Categories', 'subtitle' => 'Which document types move through approval quickest.', 'rows' => $fastestCategories, 'empty' => 'Not enough decision history yet to rank categories.'],
        ],
        'slowest' => [
            ['title' => 'Slowest Approvers', 'subtitle' => 'Ranked by average time from assignment to decision.', 'rows' => $slowestApprovers, 'empty' => 'Not enough decision history yet to rank approvers.'],
            ['title' => 'Slowest Departments', 'subtitle' => 'Same ranking, grouped by department.', 'rows' => $slowestDepartments, 'empty' => 'Not enough decision history yet to rank departments.'],
            ['title' => 'Slowest Categories', 'subtitle' => 'Which document types move through approval slowest.', 'rows' => $slowestCategories, 'empty' => 'Not enough decision history yet to rank categories.'],
        ],
    ];
@endphp

{{-- The toggle itself — a segmented pill, not two plain buttons, so the
     active side is unmistakable at a glance (solid fill + white text vs
     a plain hover state), matching the "must have a design to know it's
     clicked" ask. Delegated click handling lives in
     performance_insights.blade.php's script, since this whole fragment
     (toggle included) gets replaced wholesale on every live swap. --}}
<div class="flex justify-center mb-4">
    <div class="inline-flex items-center gap-1 rounded-full border border-surface-200 bg-white shadow-card p-1">
        <button type="button" data-perf-mode-btn="fastest" aria-pressed="true"
            class="px-5 py-2 rounded-full text-xs font-semibold transition-colors bg-primary-700 text-white">
            Fastest
        </button>
        <button type="button" data-perf-mode-btn="slowest" aria-pressed="false"
            class="px-5 py-2 rounded-full text-xs font-semibold transition-colors text-surface-600 hover:bg-surface-100">
            Slowest
        </button>
    </div>
</div>

@foreach($modes as $mode => $panels)
<div data-perf-mode-panel="{{ $mode }}" class="grid grid-cols-1 lg:grid-cols-3 gap-4 {{ $mode === 'fastest' ? '' : 'hidden' }}">
    @foreach($panels as $panel)
        <div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-surface-200">
                <h2 class="text-sm font-semibold text-surface-900 tracking-tight">{{ $panel['title'] }}</h2>
                <p class="text-xs text-surface-400 mt-0.5">{{ $panel['subtitle'] }}</p>
            </div>
            <ul class="divide-y divide-surface-100">
                @forelse($panel['rows'] as $i => $row)
                    <li class="px-5 py-3 flex items-center gap-3">
                        <span class="w-5 h-5 shrink-0 rounded-full flex items-center justify-center text-[11px] font-bold {{ $i === 0 ? 'bg-approved-500 text-white' : 'bg-surface-100 text-surface-500' }}">{{ $i + 1 }}</span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-surface-800 truncate">{{ $row['label'] }}</p>
                            <p class="text-xs text-surface-400">{{ $row['decisions_count'] }} decision{{ $row['decisions_count'] === 1 ? '' : 's' }}</p>
                        </div>
                        <span class="shrink-0 text-xs font-semibold text-surface-700 tabular-nums">{{ \Carbon\CarbonInterval::seconds($row['avg_seconds'])->cascade()->forHumans(['short' => true]) }}</span>
                    </li>
                @empty
                    <li class="px-5 py-8 text-center text-xs text-surface-400">{{ $panel['empty'] }}</li>
                @endforelse
            </ul>
        </div>
    @endforeach
</div>
@endforeach
