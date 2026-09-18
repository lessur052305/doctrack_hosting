@extends('layouts.app')
@section('title', 'Performance Insights')
@section('page-title', 'Performance Insights')

@section('content')
<div class="space-y-6">
    {{-- Always-visible metrics description (Feature: explain how these
         rankings are produced without hiding it behind a click) — the
         four bullets mirror PerformanceInsightsService::rank()'s actual
         logic exactly (MIN_DECISIONS floor, business-hours-aware average,
         sorted ascending), so this never drifts from what the numbers
         really are. --}}
    <div class="rounded-lg bg-processing-50 border border-processing-500/30 text-processing-700 px-4 py-3 text-xs space-y-1">
        <p class="font-semibold">How these rankings are calculated:</p>
        <ul class="list-disc list-inside space-y-0.5">
            <li>Based on real completed decisions — actual approvals and rejections people made.</li>
            <li>Each person/department/category's speed is the average real working time (business hours, 9 AM–5 PM) it took them to decide.</li>
            <li>Requires a minimum number of decisions on record before appearing in a ranking, so one lucky fast decision doesn't misrepresent someone as "fastest."</li>
            <li>Ranked fastest to slowest by that average.</li>
        </ul>
    </div>

    <div id="performance-insights-results" data-poll-url="{{ route('admin.performance.insights.poll') }}" data-refresh-url="{{ route('admin.performance.insights.refresh') }}">
        @include('admin.partials.performance-insights-results')
    </div>
</div>

<script>
    // Same live-poll pattern as every other admin module — see
    // dashboard.blade.php's comment for the full reasoning. A new decision
    // landing anywhere in the system can shift these rankings.
    document.addEventListener('DOMContentLoaded', function () {
        const resultsEl = document.getElementById('performance-insights-results');
        if (!resultsEl) return;

        const opts = {
            refreshUrl: resultsEl.dataset.refreshUrl,
            target: resultsEl,
        };

        startLiveChannel('admin-dashboard', '.admin.activity-logged', opts);
        startLivePoll({ ...opts, pollUrl: resultsEl.dataset.pollUrl });
    });
</script>
@endsection
