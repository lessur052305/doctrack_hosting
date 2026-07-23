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
@if($reviewQueue->isNotEmpty())
    <div class="lg:col-span-3">
        <div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
            <div class="px-6 py-3 border-b border-surface-200">
                <h2 class="text-sm font-semibold text-surface-900">Awaiting ML Review ({{ $reviewQueue->count() }})</h2>
                <p class="text-xs text-surface-500 mt-0.5">
                    These documents classified below {{ config('ml.review_confidence_threshold', 70) }}% confidence and are being <strong>held —
                    not yet routed to any approver</strong>. <strong>Confirm</strong> routes it for approval using the category you pick and adds
                    it to training staging. <strong>Reject</strong> if no category genuinely fits — the originator will be asked to resubmit.
                    Documents under {{ $priorityThreshold }}% are marked <span class="font-medium text-rejected-600">High priority</span> —
                    the model was essentially guessing.
                </p>
            </div>
            <ul class="divide-y divide-surface-100">
                @foreach($reviewQueue as $entry)
                    @php
                        $doc = $entry->document;
                    @endphp
                    <li class="px-6 py-4">
                        <p class="text-sm font-medium text-surface-800 truncate">
                            {{ $doc->title }}
                        </p>
                        <p class="text-xs text-surface-400 mt-0.5">
                            Predicted: <span class="font-medium text-surface-600">{{ $doc->ml_category ?? 'Unclassified' }}</span>
                            &middot;
                            <span class="font-medium {{ $entry->isPriority ? 'text-rejected-600' : 'text-processing-700' }}">
                                {{ $doc->ml_confidence }}% confidence{{ $entry->isPriority ? ' — High priority' : '' }}
                            </span>
                            &middot;
                            <button type="button"
                                onclick="openDocumentViewer('{{ route('documents.file', $doc) }}', '{{ $doc->mime_type }}', '{{ addslashes($doc->original_filename ?? $doc->title) }}')"
                                class="font-medium text-primary-700 hover:underline">
                                View File
                            </button>
                            &middot; uploaded by {{ $doc->originator->full_name ?? 'a former account' }}
                        </p>

                        <form method="POST" action="{{ route('admin.ml.review', $doc) }}" class="mt-3 flex flex-wrap items-center gap-2">
                            @csrf
                            <select name="category" class="rounded-lg border-surface-300 text-xs px-2 py-1.5 focus:border-primary-500 focus:ring-primary-500">
                                @foreach($categories as $c)
                                    <option value="{{ $c }}" {{ $doc->ml_category === $c ? 'selected' : '' }}>{{ $c }}</option>
                                @endforeach
                            </select>
                            <button type="submit" name="action" value="confirm"
                                class="text-xs font-medium bg-approved-600 hover:bg-approved-700 text-white px-3 py-1.5 rounded-lg transition-colors">
                                Confirm
                            </button>
                            <button type="submit" name="action" value="reject"
                                class="text-xs font-medium bg-rejected-600 hover:bg-rejected-700 text-white px-3 py-1.5 rounded-lg transition-colors">
                                Reject
                            </button>
                        </form>

                        @if($entry->exactDuplicates->isNotEmpty())
                            {{-- No separate action here on purpose — these are genuinely
                                 the same document, so Confirm/Reject above already
                                 resolves them together in one decision (see
                                 reviewFlaggedDocument()'s exact-duplicate handling). This
                                 is just visibility into what that click will also affect. --}}
                            <p class="mt-2 text-[11px] text-surface-400 italic">
                                @if($entry->exactDuplicates->count() === 1)
                                    Will also resolve another identical document ({{ $entry->exactDuplicates->first()->title }})
                                @else
                                    Will also resolve {{ $entry->exactDuplicates->count() }} other identical documents
                                    ({{ $entry->exactDuplicates->pluck('title')->implode(', ') }})
                                @endif
                            </p>
                        @endif

                        @if($entry->similar->isNotEmpty())
                            {{-- Grouping is a display convenience, not a merge — confirming/
                                 rejecting the primary above has no effect on these, so each
                                 one still needs its own reachable action, not just a count.
                                 Collapsed by default since they're usually true near-copies,
                                 but never hidden past a click. --}}
                            <details class="mt-3 border border-surface-100 rounded-lg overflow-hidden">
                                <summary class="cursor-pointer select-none px-3 py-1.5 text-xs font-medium text-surface-600 bg-surface-50/50 hover:bg-surface-100">
                                    +{{ $entry->similar->count() }} similar document{{ $entry->similar->count() === 1 ? '' : 's' }} — click to view/act individually
                                </summary>
                                <ul class="divide-y divide-surface-100 border-t border-surface-100">
                                    @foreach($entry->similar as $sim)
                                        <li class="px-3 py-3">
                                            <p class="text-xs font-medium text-surface-700 truncate">{{ $sim->title }}</p>
                                            <p class="text-[11px] text-surface-400 mt-0.5">
                                                Predicted: <span class="font-medium text-surface-600">{{ $sim->ml_category ?? 'Unclassified' }}</span>
                                                &middot; {{ $sim->ml_confidence }}% confidence
                                                &middot;
                                                <button type="button"
                                                    onclick="openDocumentViewer('{{ route('documents.file', $sim) }}', '{{ $sim->mime_type }}', '{{ addslashes($sim->original_filename ?? $sim->title) }}')"
                                                    class="font-medium text-primary-700 hover:underline">
                                                    View File
                                                </button>
                                                &middot; uploaded by {{ $sim->originator->full_name ?? 'a former account' }}
                                            </p>
                                            <form method="POST" action="{{ route('admin.ml.review', $sim) }}" class="mt-2 flex flex-wrap items-center gap-2">
                                                @csrf
                                                <select name="category" class="rounded-lg border-surface-300 text-[11px] px-2 py-1 focus:border-primary-500 focus:ring-primary-500">
                                                    @foreach($categories as $c)
                                                        <option value="{{ $c }}" {{ $sim->ml_category === $c ? 'selected' : '' }}>{{ $c }}</option>
                                                    @endforeach
                                                </select>
                                                <button type="submit" name="action" value="confirm"
                                                    class="text-[11px] font-medium bg-approved-600 hover:bg-approved-700 text-white px-2.5 py-1 rounded-lg transition-colors">
                                                    Confirm
                                                </button>
                                                <button type="submit" name="action" value="reject"
                                                    class="text-[11px] font-medium bg-rejected-600 hover:bg-rejected-700 text-white px-2.5 py-1 rounded-lg transition-colors">
                                                    Reject
                                                </button>
                                            </form>
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endif

@if($stagedFromReview->isNotEmpty())
    <div class="lg:col-span-3">
        <div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
            <div class="px-6 py-3 border-b border-surface-200">
                <h3 class="text-sm font-semibold text-surface-900">Confirmed From Review — Re-check After Retraining</h3>
                <p class="text-xs text-surface-500 mt-0.5">
                    Once you've retrained the model on these, click "Re-check" to see whether this exact document now classifies with higher confidence.
                </p>
            </div>
            <ul class="divide-y divide-surface-100">
                @foreach($stagedFromReview as $doc)
                    <li class="px-6 py-3 flex items-center justify-between gap-4 flex-wrap">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-surface-800 truncate flex items-center gap-2 flex-wrap">
                                {{ $doc->title }}
                                <button type="button"
                                    onclick="openDocumentViewer('{{ route('documents.file', $doc) }}', '{{ $doc->mime_type }}', '{{ addslashes($doc->original_filename ?? $doc->title) }}')"
                                    class="text-xs font-medium text-primary-700 hover:underline flex-shrink-0">
                                    View File
                                </button>
                            </p>
                            <p class="text-xs text-surface-400 mt-0.5">
                                Originally: {{ $doc->ml_category }} at {{ $doc->ml_confidence }}%
                                @if($doc->ml_rechecked_at)
                                    &middot; Re-checked {{ $doc->ml_rechecked_at->diffForHumans() }}:
                                    <span class="font-medium {{ $doc->ml_recheck_confidence > $doc->ml_confidence ? 'text-approved-700' : 'text-surface-600' }}">
                                        {{ $doc->ml_recheck_category }} at {{ $doc->ml_recheck_confidence }}%
                                    </span>
                                @endif
                            </p>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            @if($activeModelId !== null && $activeModelId !== $doc->confirmed_at_model_id)
                                <form method="POST" action="{{ route('admin.ml.review.recheck', $doc) }}">
                                    @csrf
                                    <button type="submit"
                                        class="text-xs font-medium bg-primary-700 hover:bg-primary-800 text-white px-3 py-1.5 rounded-lg transition-colors">
                                        Re-check
                                    </button>
                                </form>
                            @else
                                <span class="text-xs text-surface-400 italic" title="Retrain the model to enable re-checking this document">
                                    Waiting for the next retrain
                                </span>
                            @endif
                            @if($doc->ml_rechecked_at)
                                <form method="POST" action="{{ route('admin.ml.review.dismiss', $doc) }}">
                                    @csrf
                                    <button type="submit" title="Dismiss — done watching this one"
                                        class="w-7 h-7 flex items-center justify-center rounded-lg text-surface-400 hover:text-rejected-600 hover:bg-rejected-50 transition-colors">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </form>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
