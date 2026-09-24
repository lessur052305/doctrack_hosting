@extends('layouts.app')
@section('title', 'Performance Insights')
@section('page-title', 'Performance Insights')

@section('content')
<div class="space-y-6">
    {{-- Always-visible metrics description (Feature: explain how these
         rankings are produced without hiding it behind a click) — the
         bullets mirror PerformanceInsightsService::rank()'s actual logic
         exactly (MIN_DECISIONS floor, business-hours-aware average, sorted
         either direction — see fastestApprovers()/slowestApprovers() and
         friends), so this never drifts from what the numbers really are.
         Worded direction-neutral throughout (not just "fastest") now that
         the toggle below makes Slowest an equally real view, not a
         secondary one. --}}
    <div class="rounded-lg bg-processing-50 border border-processing-500/30 text-processing-700 px-4 py-3 text-xs space-y-1">
        <p class="font-semibold">How these rankings are calculated:</p>
        <ul class="list-disc list-inside space-y-0.5">
            <li>Based on real completed decisions — actual approvals and rejections people made.</li>
            <li>Each person/department/category's speed is the average real working time (business hours, 9 AM–5 PM) it took them to decide.</li>
            <li>Requires a minimum number of decisions on record before appearing in a ranking, so one lucky decision doesn't misrepresent someone as "fastest" or "slowest."</li>
            <li>Sorted by that average — toggle Fastest / Slowest below to see either end of the same ranking.</li>
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

        // Fastest/Slowest toggle — see admin/partials/
        // performance-insights-results.blade.php for why both directions
        // are already in the DOM (no request on click). currentMode lives
        // in this outer closure (not reset per-swap) so a live update
        // below re-applies whichever side was showing instead of silently
        // snapping back to Fastest.
        let currentMode = 'fastest';

        function setButtonState(mode) {
            resultsEl.querySelectorAll('[data-perf-mode-btn]').forEach((btn) => {
                const active = btn.dataset.perfModeBtn === mode;
                btn.classList.toggle('bg-primary-700', active);
                btn.classList.toggle('text-white', active);
                btn.classList.toggle('text-surface-600', !active);
                btn.classList.toggle('hover:bg-surface-100', !active);
                btn.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        }

        function showPanel(mode) {
            resultsEl.querySelectorAll('[data-perf-mode-panel]').forEach((panel) => {
                panel.classList.toggle('hidden', panel.dataset.perfModePanel !== mode);
            });
        }

        // Instant — no animation. Used after a live swap replaces this
        // whole fragment with fresh markup (see onSwap below): there's
        // nothing to crossfade FROM at that point, so animating here would
        // just be a pointless flash on every background update.
        function applyMode(mode) {
            currentMode = mode;
            showPanel(mode);
            setButtonState(mode);
        }

        // Delegated, not bound directly to the buttons — this whole
        // fragment (toggle included) gets replaced wholesale on every
        // live swap below, same reasoning as every other delegated
        // listener in this app (see originator/dashboard.blade.php's
        // matching comment).
        resultsEl.addEventListener('click', function (e) {
            const btn = e.target.closest('[data-perf-mode-btn]');
            if (!btn) return;
            const mode = btn.dataset.perfModeBtn;
            if (mode === currentMode) return;

            // The button's own background-color fade is plain CSS
            // (transition-colors, already on the buttons) — updated
            // immediately and left alone to animate on its own. This is
            // deliberately NOT wrapped in document.startViewTransition():
            // that API snapshots the WHOLE screen into one before/after
            // image pair and cross-dissolves between them, which froze
            // this exact color transition for most of its duration
            // instead of letting it play smoothly (confirmed by sampling
            // the button's computed background-color frame by frame — a
            // real, measured bug, not a style preference).
            currentMode = mode;
            setButtonState(mode);

            // Manual opacity crossfade for the panel content instead, run
            // independently of the button above so neither one can freeze
            // the other. Panels carry transition-opacity (see
            // performance-insights-results.blade.php) to animate this.
            const visiblePanel = resultsEl.querySelector('[data-perf-mode-panel]:not(.hidden)');
            const nextPanel = resultsEl.querySelector(`[data-perf-mode-panel="${mode}"]`);
            if (!visiblePanel || !nextPanel || visiblePanel === nextPanel) {
                showPanel(mode);
                return;
            }

            visiblePanel.style.opacity = '0';
            setTimeout(() => {
                visiblePanel.classList.add('hidden');
                visiblePanel.style.opacity = ''; // reset for the next time this panel is shown
                nextPanel.classList.remove('hidden');
                nextPanel.style.opacity = '0';
                void nextPanel.offsetWidth; // force a reflow so the fade-in below isn't batched away with the line above
                nextPanel.style.opacity = '1';
            }, 150);
        });

        const opts = {
            refreshUrl: resultsEl.dataset.refreshUrl,
            target: resultsEl,
            onSwap: () => applyMode(currentMode),
        };

        startLiveChannel('admin-dashboard', '.admin.activity-logged', opts);
        startLivePoll({ ...opts, pollUrl: resultsEl.dataset.pollUrl });
    });
</script>
@endsection
