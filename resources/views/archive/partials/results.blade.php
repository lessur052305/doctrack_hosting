{{--
    Extracted from archive/index.blade.php so ArchiveController::refresh()
    can return exactly this fragment for the live-search JS to swap in,
    without re-rendering the whole page (filter bar, sidebar, layout).
    Expects: $documents, $isOwnSubmissionsView.
--}}
<div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-surface-200 flex items-center justify-between">
        <div>
            <h2 class="text-sm font-semibold text-surface-900">Approved Documents</h2>
            @if($isOwnSubmissionsView)
                <p class="text-xs text-surface-400 mt-0.5">Showing only documents you submitted, across all categories.</p>
            @endif
        </div>
        <span class="text-xs text-surface-400">{{ $documents->total() }} total</span>
    </div>
    <div class="overflow-x-auto">
    <table class="w-full min-w-[640px] text-sm">
        <thead class="bg-surface-50 text-surface-500 text-xs uppercase tracking-wide">
            <tr>
                <th class="text-left px-6 py-3 font-medium">Document</th>
                <th class="text-left px-6 py-3 font-medium">Category</th>
                <th class="text-left px-6 py-3 font-medium">Originator</th>
                <th class="text-left px-6 py-3 font-medium">Approved</th>
                <th class="px-6 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-surface-100">
            @forelse($documents as $doc)
                <tr class="hover:bg-surface-50 transition-colors">
                    <td class="px-6 py-3 font-medium text-surface-800 max-w-xs truncate">
                        {{ $doc->title }}
                        @if($doc->is_legacy_import)
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-processing-50 text-processing-700 align-middle">Imported</span>
                        @endif
                        @if($doc->disputed_at)
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-rejected-50 text-rejected-700 align-middle">Disputed</span>
                        @endif
                    </td>
                    <td class="px-6 py-3 text-surface-600">{{ $doc->ml_category }}</td>
                    <td class="px-6 py-3 text-surface-500">{{ $doc->originator->full_name ?? '—' }}</td>
                    <td class="px-6 py-3 text-surface-500">{{ $doc->updated_at->format('M j, Y') }}</td>
                    <td class="px-6 py-3 text-right">
                        <a href="{{ route('archive.download', $doc) }}" class="text-primary-700 hover:text-primary-900 font-medium text-xs">Download &darr;</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-6 py-10 text-center text-surface-400 text-sm">No archived documents match your search.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
    </div>
    @if($documents->hasPages())
        <div class="px-6 py-4 border-t border-surface-200">{{ $documents->links() }}</div>
    @endif
</div>
