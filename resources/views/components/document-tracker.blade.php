@props(['document', 'fill' => false, 'idSuffix' => null])

{{--
    The table-style "Document Tracker" (Timestamp / Action / Employee /
    Description) — the canonical rendering of a document's full history
    (App\Services\DocumentMovementTimeline::build()), used everywhere that
    history is shown: the document's own Tracking page (originator/
    partials/tracking-content.blade.php, $fill="true" so it grows to fill
    the rest of that page), and every "click a row to expand its history
    inline" spot — Admin's Document Tracking module isn't one of these
    (it links straight to the Tracking page instead), but Audit Logs,
    Approver Decision History, and Archive all are. Those three get a
    fixed, generous scroll cap instead of $fill — "grow to fill the rest
    of the screen" only makes sense for the page's own primary content,
    not something nested inside an already-paginated list row, and an
    unbounded list there is exactly what used to clip past Audit Logs'
    own capped-height container.

    $idSuffix keeps the scroll area's id unique when more than one of
    these can exist on the same page at once (one per expandable row) —
    omitted entirely (the document's own Tracking page, where there's
    only ever one) so sizeDocumentTracker() in tracking.blade.php keeps
    targeting the exact id it always has.
--}}
@php
    $movementTimeline = \App\Services\DocumentMovementTimeline::build($document);
    $scrollId = 'document-tracker-scroll' . ($idSuffix !== null ? '-' . $idSuffix : '');
@endphp

<div class="px-6 py-4 border-b border-surface-200 shrink-0">
    <h3 class="text-sm font-semibold text-surface-900">Document Tracker</h3>
</div>
<div id="{{ $scrollId }}" class="overflow-x-auto overflow-y-auto {{ $fill ? 'flex-1 min-h-0' : 'max-h-96' }}">
    {{-- No explicit z-index on the sticky header — sticky already paints
         above the table's own scrolling rows from normal stacking order
         alone; adding one here previously created a stacking context that
         won against the notification dropdown elsewhere on the page
         (z-30), making Document Tracker labels incorrectly appear on top
         of it. --}}
    <table class="w-full text-sm border-collapse">
        <thead class="sticky top-0 bg-white">
            <tr class="border-b-2 border-surface-200 text-left text-xs uppercase tracking-wide text-surface-400">
                <th class="px-6 py-2 font-medium border-r border-surface-200">Timestamp</th>
                <th class="px-4 py-2 font-medium border-r border-surface-200">Action</th>
                <th class="px-4 py-2 font-medium border-r border-surface-200">Employee</th>
                <th class="px-6 py-2 font-medium">Description</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-surface-200">
            @forelse($movementTimeline as $event)
                <tr>
                    <td class="px-6 py-3 text-sm text-surface-400 whitespace-nowrap align-top border-r border-surface-200">{{ $event['timestamp']->format('M j, Y g:i A') }}</td>
                    <td class="px-4 py-3 align-top border-r border-surface-200">
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-semibold whitespace-nowrap
                            {{ $event['kind'] === 'session_group' ? 'bg-primary-50 text-primary-700' : 'bg-surface-100 text-surface-600' }}">
                            {{ $event['label'] }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-surface-700 font-medium align-top whitespace-nowrap border-r border-surface-200">{{ $event['actor'] }}</td>
                    <td class="px-6 py-3 text-surface-500 align-top">
                        @if($event['kind'] === 'session_group')
                            {{-- One row per person, every individual pass
                                 shown plainly underneath — see
                                 DocumentMovementTimeline::build()'s docblock. --}}
                            {{ $event['note'] }}
                            <p class="mt-1 text-sm text-surface-400">{{ $event['passes_detail'] }}</p>
                        @else
                            {{ $event['note'] }}
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="px-6 py-6 text-center text-sm text-surface-400">No recorded activity yet.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
