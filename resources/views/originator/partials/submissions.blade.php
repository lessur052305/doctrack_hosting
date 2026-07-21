{{--
    The submissions table + pagination — split out from dashboard.blade.php
    so the same markup can be rendered two ways: a normal full page load,
    and a fragment returned by DocumentController::refresh() for the
    live-poll JS to swap in place (see dashboard.blade.php) without a full
    page reload. The "N total" count in the card header is a sibling of
    this fragment, not part of it — the JS reads the value below (baked
    into the fragment itself, not the triggering event's payload, since a
    WebSocket push and a poll response don't share the same JSON shape).
--}}
<span data-total-count="{{ $documents->total() }}" class="hidden"></span>
<div class="overflow-x-auto">
<table class="w-full min-w-[640px] text-sm">
    <thead class="bg-surface-50 text-surface-500 text-xs uppercase tracking-wide">
        <tr>
            <th class="text-left px-6 py-3 font-medium">Document</th>
            <th class="text-left px-6 py-3 font-medium">Batch</th>
            <th class="text-left px-6 py-3 font-medium">Category</th>
            <th class="text-left px-6 py-3 font-medium">Status</th>
            <th class="text-left px-6 py-3 font-medium">Uploaded</th>
            <th class="px-6 py-3"></th>
        </tr>
    </thead>
    <tbody id="submission-rows" class="divide-y divide-surface-100">
        @forelse($documents as $doc)
            <tr class="submission-row hover:bg-surface-50 transition-colors" data-document-title="{{ strtolower($doc->title) }}">
                <td class="px-6 py-4 font-medium text-surface-800 max-w-xs truncate">
                    {{ $doc->title }}
                    @if($doc->version_number > 1)
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-indigo-50 text-indigo-700 align-middle">v{{ $doc->version_number }}</span>
                    @endif
                </td>
                <td class="px-6 py-4 text-surface-500">
                    @if($doc->batch)
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-primary-50 text-primary-700 ring-1 ring-inset ring-primary-500/20">
                            Batch #{{ $doc->batch_id }}
                        </span>
                    @else
                        <span class="text-surface-300">—</span>
                    @endif
                </td>
                <td class="px-6 py-4 text-surface-600">{{ $doc->ml_category ?? '—' }}</td>
                <td class="px-6 py-4"><x-status-badge :status="$doc->display_status" /></td>
                <td class="px-6 py-4 text-surface-500">{{ $doc->upload_date->diffForHumans() }}</td>
                <td class="px-6 py-4 text-right">
                    <a href="{{ route('originator.documents.show', $doc) }}" class="text-primary-700 hover:text-primary-900 font-medium text-xs">Track &rarr;</a>
                </td>
            </tr>
            @if(!$doc->is_validated && $doc->global_status === 'processing')
            <tr class="submission-row bg-rejected-50/50" data-document-title="{{ strtolower($doc->title) }}">
                <td colspan="6" class="px-6 pb-3 text-xs text-rejected-700">
                    Validation issues: {{ implode(' · ', $doc->validation_errors ?? []) }}
                </td>
            </tr>
            @endif
        @empty
            <tr>
                <td colspan="6" class="px-6 py-10 text-center text-surface-400 text-sm">
                    @if(request('document') || request('status') || request('category'))
                        No documents match these filters.
                    @else
                        No documents submitted yet.
                    @endif
                </td>
            </tr>
        @endforelse
        <tr id="submission-no-matches" class="hidden">
            <td colspan="6" class="px-6 py-10 text-center text-surface-400 text-sm">No documents on this page match "<span id="submission-no-matches-term"></span>". Press Enter to search every page.</td>
        </tr>
    </tbody>
</table>
</div>

@if($documents->hasPages())
    <div class="px-6 py-4 border-t border-surface-200">{{ $documents->links() }}</div>
@endif
