{{--
    Audit Logs results table — split out from audit_logs.blade.php so the
    same markup can be rendered two ways: a normal full page load, and a
    fragment returned by AdminController::auditLogsRefresh() for the
    live-poll JS to swap in place (see audit_logs.blade.php) without a
    full page reload.
--}}
@php
    // Same five-bucket categorization App\Services\DocumentMovementTimeline
    // uses for the nested per-document timeline — shared, not duplicated,
    // so an action is always the same color everywhere it's shown.
    $actionCategories = \App\Services\DocumentMovementTimeline::ACTION_CATEGORIES;
    $categoryClasses = \App\Services\DocumentMovementTimeline::CATEGORY_CLASSES;
    $actionLabels = \App\Services\DocumentMovementTimeline::ACTION_LABELS;
@endphp

{{-- flex-1 + overflow-hidden here (not on the pagination div below) — the
     parent #audit-results is capped to the device's screen height (see
     audit_logs.blade.php); this is what resources/js/app.js's
     initFittedPagination() measures against to decide how many rows
     actually fit, hiding the rest. --}}
<div class="overflow-x-auto flex-1 overflow-y-hidden js-adaptive-rows-area">
<table class="w-full min-w-[720px] text-sm">
    <thead class="bg-surface-50 text-surface-500 text-xs uppercase tracking-wide">
        <tr>
            <th class="text-left px-6 py-3 font-medium border-r border-surface-200">Timestamp</th>
            <th class="text-left px-6 py-3 font-medium border-r border-surface-200">Document Title</th>
            <th class="text-left px-6 py-3 font-medium border-r border-surface-200">Employee</th>
            <th class="text-left px-6 py-3 font-medium border-r border-surface-200">Action</th>
            <th class="text-left px-6 py-3 font-medium border-r border-surface-200">Track</th>
            <th class="text-left px-6 py-3 font-medium">Description</th>
        </tr>
    </thead>
    <tbody id="audit-rows" class="divide-y divide-surface-200">
        @forelse($logs as $row)
            @include('admin.partials.audit-row', ['row' => $row])
        @empty
            <tr id="audit-empty">
                <td colspan="6" class="px-6 py-10 text-center text-surface-400 text-sm">No audit entries match these filters.</td>
            </tr>
        @endforelse
        <tr id="audit-no-matches" class="hidden">
            <td colspan="6" class="px-6 py-10 text-center text-surface-400 text-sm">No entries on this page match "<span id="audit-no-matches-term"></span>". Press Enter to search every page.</td>
        </tr>
    </tbody>
</table>
</div>

{{-- Feature: client-side "fitted" pagination — see resources/js/app.js's
     initFittedPagination() for the full mechanism. Not Laravel's own
     $logs->links() — the whole list is already in the DOM above (no
     server-side page size at all now), and this container is entirely
     built by that JS using the exact same original nav look as
     resources/views/vendor/pagination/tailwind.blade.php. --}}
<div id="audit-results-pagination" class="px-6 py-4 border-t border-surface-200 flex-shrink-0"></div>
