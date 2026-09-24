{{--
    Revision History (Feature: Google-Docs-style before/after history for
    the originator's "editable document" saves — see WorkflowService::
    saveDocumentRevision()). Fetched into the shared kpi-drilldown-modal
    (openKpiDrilldown('revision-history', ...)) from both the approver's
    Review & Comment panel and the originator/admin's tracking page — same
    fetch-and-inject pattern used everywhere else in this app.

    Newest revision first, with the document's current live text pinned
    above all of them — not a revision itself, since there's nothing
    closed yet to diff it against. Clicking a past revision swaps this
    list for its before/after comparison (revision-diff.blade.php),
    delegated from kpi-drilldown-modal.blade.php since <script> tags in
    fetched markup never run.
--}}
<div data-history-root data-list-url="{{ route('documents.revisions', $document) }}">
    <ul class="divide-y divide-surface-100">
        <li class="px-6 py-3.5 bg-approved-50/50">
            <p class="text-sm font-semibold text-approved-800">Current version</p>
            <p class="text-xs text-surface-500 mt-0.5">The live text shown in Review &amp; Comment right now.</p>
        </li>

        @forelse($revisions as $revision)
            <li>
                <button type="button" data-revision-diff-url="{{ route('documents.revisions.compare', [$document, $revision]) }}"
                    class="w-full text-left px-6 py-3.5 hover:bg-surface-50 transition-colors">
                    <p class="text-sm font-medium text-surface-800">
                        {{ $revision->revisedBy->full_name ?? 'Unknown' }}
                        <span class="text-xs font-normal text-surface-400">{{ $revision->created_at->format('M j, Y g:i A') }}</span>
                    </p>
                    @if($revision->annotations->isNotEmpty())
                        <p class="text-xs text-surface-500 mt-1">
                            @foreach($revision->annotations as $annotation)
                                {{-- The viewing approver's own name bolded, so
                                     they can spot their own entry at a glance
                                     in a long pile-up of flags. --}}
                                <span class="{{ $annotation->raised_by === auth()->id() ? 'font-semibold text-surface-700' : '' }}">{{ $annotation->raisedBy->full_name ?? 'Unknown' }}</span>{{ !$loop->last ? ', ' : ' ' }}
                            @endforeach
                            Flagged Revisions
                        </p>
                    @else
                        <p class="text-xs text-surface-400 mt-1">General edit — no flags addressed.</p>
                    @endif
                </button>
            </li>
        @empty
            <li class="px-6 py-10 text-center text-sm text-surface-400">No past revisions yet.</li>
        @endforelse
    </ul>
</div>
