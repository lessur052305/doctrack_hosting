{{--
    Before/after comparison for one revision (Feature: Revision History —
    see TextDiffService). Left pane is the text as it stood before this
    save with removed words highlighted red, right pane is the text right
    after with added words highlighted green — the same convention a
    Git/Google Docs diff uses. The back arrow re-fetches the list
    (revision-history-list.blade.php) into the same modal body, delegated
    from kpi-drilldown-modal.blade.php since <script> tags in fetched
    markup never run.

    Built as plain PHP string concatenation, not a Blade foreach loop in
    the template body — same reasoning as annotations-panel.blade.php's
    identical php block: a more "readable" loop leaks its own
    indentation/newlines into pre-wrap text, and mixing Blade directive
    text into a comment right before a real php block has its own parse
    pitfalls (confirmed the hard way — even mentioning the directive names
    by their at-sign spelling in a comment here breaks compilation of the
    block that follows).
--}}
@php
    $beforeHtml = '';
    foreach ($diff['before'] as $chunk) {
        $beforeHtml .= $chunk['type'] === 'removed'
            ? '<mark class="bg-rejected-100 text-rejected-900 rounded px-0.5">' . e($chunk['text']) . '</mark>'
            : e($chunk['text']);
    }
    $afterHtml = '';
    foreach ($diff['after'] as $chunk) {
        $afterHtml .= $chunk['type'] === 'added'
            ? '<mark class="bg-approved-100 text-approved-900 rounded px-0.5">' . e($chunk['text']) . '</mark>'
            : e($chunk['text']);
    }
@endphp
<div data-diff-root data-list-url="{{ route('documents.revisions', $document) }}">
    <div class="px-6 py-3 border-b border-surface-200 bg-surface-50/60 flex items-center gap-3">
        <button type="button" data-back-to-history class="p-1 -ml-1 rounded-lg hover:bg-surface-100 text-surface-500 flex-shrink-0" aria-label="Back to history">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        </button>
        <div class="min-w-0">
            <p class="text-sm font-semibold text-surface-900">
                {{ $revision->revisedBy->full_name ?? 'Unknown' }}
                <span class="text-xs font-normal text-surface-400">{{ $revision->created_at->format('M j, Y g:i A') }}</span>
            </p>
            @if($revision->annotations->isNotEmpty())
                <p class="text-xs text-surface-500">
                    @foreach($revision->annotations as $annotation)
                        <span class="{{ $annotation->raised_by === auth()->id() ? 'font-semibold text-surface-700' : '' }}">{{ $annotation->raisedBy->full_name ?? 'Unknown' }}</span>{{ !$loop->last ? ', ' : ' ' }}
                    @endforeach
                    Flagged Revisions
                </p>
            @endif
        </div>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 p-4 bg-surface-50">
        <div class="bg-white rounded-lg border-2 border-rejected-200 p-4">
            <p class="text-xs font-semibold text-rejected-700 uppercase tracking-wide mb-2">Before</p>
            <p class="text-sm text-surface-800 leading-relaxed" style="white-space: pre-wrap;">{!! $beforeHtml !!}</p>
        </div>
        <div class="bg-white rounded-lg border-2 border-approved-200 p-4">
            <p class="text-xs font-semibold text-approved-700 uppercase tracking-wide mb-2">After</p>
            <p class="text-sm text-surface-800 leading-relaxed" style="white-space: pre-wrap;">{!! $afterHtml !!}</p>
        </div>
    </div>
</div>
