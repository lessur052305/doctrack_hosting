{{--
    The Awaiting ML Review + Confirmed From Review panels — split out so
    the same markup renders both a normal full page load AND the fragment
    AdminController::mlReviewQueueRefresh() returns for the live-channel/
    poll JS on ml_training.blade.php to swap into #ml-review-panels
    (see resources/js/app.js's applyLiveRefresh pattern). The parent
    #ml-review-panels div always renders, even when both queues are empty,
    specifically so a live swap can inject a panel that didn't exist in the
    DOM yet — the panels here rendering conditionally is fine, wrapping
    them in something that ALWAYS renders is what makes an in-place swap
    possible when the very first item shows up.
--}}
@php
    // Same "how much real business time is left before due_date" signal
    // WorkflowService::extendDueDateIfReviewQueueAteTheBuffer() reacts to —
    // surfaced on both queues below so an Admin sees an item running low
    // on runway BEFORE that safety net has to kick in and auto-extend the
    // due date on their behalf. Defined once here (not inside either
    // @foreach below) since either queue can legitimately be empty while
    // the other isn't.
    $dueUrgencyStyles = [
        1 => ['Urgent', 'bg-rejected-50 text-rejected-700 ring-rejected-500/20'],
        2 => ['Normal', 'bg-processing-50 text-processing-700 ring-processing-500/20'],
        3 => ['Low', 'bg-surface-100 text-surface-600 ring-surface-300'],
        4 => ['Expired', 'bg-rejected-100 text-rejected-800 ring-rejected-500/40'],
    ];
@endphp
{{-- "Awaiting ML Review" (classification-confidence hold) and "Confirmed
     From Review" / Re-check are retired — see WorkflowService::ingest()'s
     $isAmbiguous docblock. Classification is now fully automatic: a
     confident-enough guess (high confidence, or a clear margin over the
     runner-up even at moderate confidence) routes on its own; a genuinely
     ambiguous one is rejected straight back to the originator to resubmit,
     with no admin queue in between. Content Readability Review below is a
     separate, unrelated gate and stays exactly as it was. --}}

@if($readabilityQueue->isNotEmpty())
    <div class="lg:col-span-3">
        <div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
            <div class="px-6 py-3 border-b border-surface-200">
                <h2 class="text-sm font-semibold text-surface-900">Content Readability Review ({{ $readabilityQueue->total() }})</h2>
                <p class="text-xs text-surface-500 mt-0.5">
                    These documents passed classification and every required section, but their content didn't clearly match known
                    vocabulary for their category and are being <strong>held — not yet routed to any approver</strong>.
                    <strong>Confirm</strong> stages this document into that category's training vocabulary (so future documents with the
                    same wording score higher too) and routes it for approval. <strong>Reject</strong> if the content genuinely looks
                    garbled or wrong — the originator will be asked to resubmit.
                </p>
            </div>
            <ul class="divide-y divide-surface-100">
                @foreach($readabilityQueue as $doc)
                    @php
                        [$dueLabel, $dueClass] = $dueUrgencyStyles[$doc->dueDateUrgencyRank()] ?? [null, null];
                    @endphp
                    <li class="px-6 py-4">
                        <p class="text-sm font-medium text-surface-800 truncate flex items-center gap-2">
                            <span>{{ $doc->title }}</span>
                            @if($dueLabel)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold ring-1 ring-inset {{ $dueClass }}" title="Time remaining before due date">{{ $dueLabel }}</span>
                            @endif
                        </p>
                        <p class="text-xs text-surface-400 mt-0.5">
                            Category: <span class="font-medium text-surface-600">{{ $doc->ml_category }}</span>
                            &middot;
                            <span class="font-medium text-processing-700">{{ $doc->readability_score }}% readability</span>
                            (needs {{ config('ml.min_real_word_ratio', 0.7) * 100 }}%)
                            &middot;
                            <button type="button"
                                onclick="openDocumentViewer('{{ route('documents.file', $doc) }}', '{{ $doc->mime_type }}', '{{ addslashes($doc->original_filename ?? $doc->title) }}')"
                                class="font-medium text-primary-700 hover:underline">
                                View File
                            </button>
                            &middot; uploaded by {{ $doc->originator->full_name ?? 'a former account' }}
                        </p>

                        <form method="POST" action="{{ route('admin.ml.review.readability', $doc) }}" class="mt-3 flex flex-wrap items-center gap-2">
                            @csrf
                            <button type="submit" name="action" value="confirm"
                                class="text-xs font-medium bg-approved-600 hover:bg-approved-700 text-white px-3 py-1.5 rounded-lg transition-colors">
                                Confirm
                            </button>
                            <button type="submit" name="action" value="reject"
                                class="text-xs font-medium bg-rejected-600 hover:bg-rejected-700 text-white px-3 py-1.5 rounded-lg transition-colors">
                                Reject
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
            @if($readabilityQueue->hasPages())
                <div class="px-6 py-4 border-t border-surface-200">{{ $readabilityQueue->links() }}</div>
            @endif
        </div>
    </div>
@endif

{{-- "Confirmed From Review" / Re-check retired along with "Awaiting ML
     Review" above — it only ever existed to re-check documents an admin
     manually confirmed out of that now-retired queue. --}}
