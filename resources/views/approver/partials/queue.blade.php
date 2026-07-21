{{--
    The approver's queue list — split out from dashboard.blade.php so the
    same markup can be rendered two ways: a normal full page load, and a
    fragment returned by ApprovalController::queueFragment() for the
    polling JS to swap in place (see the script block in dashboard.blade.php)
    without a full page reload.
--}}
@forelse($containers as $container)
    @php
        $docCount = $container->documents->count();
    @endphp
    <div class="review-container rounded-xl border {{ $container->is_batch ? 'border-primary-200 bg-primary-50/30' : 'border-surface-200 bg-white' }} shadow-card hover:shadow-card-hover transition-shadow overflow-hidden" data-document-titles="{{ strtolower($container->documents->map(fn ($stageAssignments) => $stageAssignments->first()->document->title)->implode('|')) }}">

        {{-- Batch header — only shown when 2+ documents were submitted together --}}
        @if($container->is_batch)
            <div class="px-6 py-3 bg-primary-100/60 border-b border-primary-200 flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <span class="w-6 h-6 rounded-md bg-primary-200/70 flex items-center justify-center flex-shrink-0">
                        <svg class="w-3.5 h-3.5 text-primary-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                    </span>
                    <span class="text-xs font-semibold text-primary-800">Submitted Document/s - {{ $docCount }}</span>
                    <span class="text-xs text-surface-500">by {{ $container->originator->full_name }}</span>
                </div>
                <div class="text-xs font-medium {{ $container->due_date && $container->due_date->isPast() ? 'text-rejected-700' : 'text-surface-600' }}">
                    Due {{ $container->due_date?->format('M j, Y g:i A') ?? '—' }}
                </div>
            </div>
        @endif

        <div class="divide-y divide-surface-100">
            @foreach($container->documents as $documentId => $stageAssignments)
                @php $doc = $stageAssignments->first()->document; @endphp
                <div class="p-6">
                    @if(!$container->is_batch)
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs text-surface-400">Single-document request</span>
                            <span class="text-xs font-medium {{ $container->due_date && $container->due_date->isPast() ? 'text-rejected-700' : 'text-surface-600' }}">
                                Due {{ $container->due_date?->format('M j, Y g:i A') ?? '—' }}
                            </span>
                        </div>
                    @endif

                    <div class="flex items-center gap-2 mb-1">
                        <h3 class="text-sm font-semibold text-surface-900">{{ $doc->title }}</h3>
                        <span class="text-xs text-surface-400">· {{ $doc->ml_category }}</span>
                    </div>
                    <p class="text-xs text-surface-500 mb-3">
                        Submitted by {{ $doc->originator->full_name }} ·
                        <button type="button"
                           onclick="openDocumentViewer('{{ route('documents.file', $doc) }}', '{{ $doc->mime_type }}', '{{ addslashes($doc->original_filename ?? $doc->title) }}')"
                           class="text-primary-700 hover:underline font-medium">
                            View original file
                        </button>
                    </p>

                    {{-- Full stage pipeline for this document's category, so approvers can
                         see what already happened and what's still to come — not just
                         whichever single stage currently needs a decision. --}}
                    <div class="mb-4">
                        <x-workflow-stage-list :document="$doc" />
                    </div>

                    {{-- Full stage pipeline above already highlights which of these belong
                         to you and which one is "Your turn" — this action targets only that
                         one. If you also hold a later stage on this same document, it stays
                         visible up there as "Up next" and becomes actionable here once this
                         one is resolved. --}}
                    @php
                        $activeAssignment = $stageAssignments->sortBy(fn ($a) => $a->stage->sequence_order)->first();
                        $priorityMap = [1 => ['Urgent', 'bg-rejected-50 text-rejected-700 ring-rejected-500/20'],
                                         2 => ['Normal', 'bg-processing-50 text-processing-700 ring-processing-500/20'],
                                         3 => ['Low', 'bg-surface-100 text-surface-600 ring-surface-300']];
                        // priority_rank reflects the document's overall due-date urgency, not
                        // this specific stage's own SLA countdown — once that's already past
                        // (should be rare: ApprovalController escalates expired assignments
                        // out of this queue on load/decide), "Urgent" would be misleading, so
                        // "Expired" takes precedence as a defensive override.
                        $isExpired = $activeAssignment->seconds_remaining !== null && $activeAssignment->seconds_remaining <= 0;
                        [$pLabel, $pClass] = $isExpired
                            ? ['Expired', 'bg-rejected-100 text-rejected-800 ring-rejected-500/40']
                            : ($priorityMap[$activeAssignment->priority_rank] ?? $priorityMap[2]);
                    @endphp
                    <div class="flex flex-col sm:flex-row sm:items-center gap-4 rounded-xl border {{ $activeAssignment->escalated_to_admin ? 'border-rejected-200 bg-rejected-50/40' : 'border-primary-200 bg-primary-50/40' }} p-4 shadow-sm">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold ring-1 ring-inset {{ $pClass }}">{{ $pLabel }}</span>
                                <span class="text-xs text-surface-500 font-medium">Stage: {{ $activeAssignment->stage->stage_name }}</span>
                            </div>
                            <p class="text-xs text-surface-400">
                                SLA expires
                                <span class="font-semibold {{ $activeAssignment->seconds_remaining !== null && $activeAssignment->seconds_remaining < 3600 ? 'text-rejected-700' : 'text-surface-600' }}"
                                      data-live-time="{{ optional($activeAssignment->sla_expires_at)->timestamp }}"
                                      data-live-urgent-under="3600">
                                    {{ $activeAssignment->sla_expires_at?->diffForHumans() ?? '—' }}
                                </span>
                            </p>
                            @if($activeAssignment->escalated_to_admin)
                                <p class="text-xs text-rejected-700 font-medium mt-1">
                                    You missed this SLA — it's been escalated to Admin and can no longer be approved or rejected here.
                                    This will drop off your queue {{ $activeAssignment->sla_expires_at->copy()->addHours(24)->diffForHumans() }}.
                                </p>
                            @elseif(!app(App\Services\BusinessHoursService::class)->isWithinWorkingWindow(now()))
                                <p class="text-[11px] text-surface-400 mt-0.5">
                                    SLA clock is paused outside business hours right now — it only counts down during working hours, so this may look longer than the actual review window.
                                </p>
                            @endif
                        </div>

                        @if($activeAssignment->escalated_to_admin)
                            <div class="flex flex-col sm:w-64 gap-2">
                                <textarea rows="1" placeholder="Optional comments…" disabled
                                    class="w-full rounded-lg border-surface-200 bg-surface-100 text-xs text-surface-400 px-3 py-2 cursor-not-allowed"></textarea>
                                <div class="flex gap-2">
                                    <button type="button" disabled title="Escalated to Admin — no longer actionable here"
                                        class="flex-1 bg-surface-200 text-surface-400 text-xs font-semibold py-2 rounded-lg cursor-not-allowed">
                                        Approve
                                    </button>
                                    <button type="button" disabled title="Escalated to Admin — no longer actionable here"
                                        class="flex-1 bg-surface-200 text-surface-400 text-xs font-semibold py-2 rounded-lg cursor-not-allowed">
                                        Reject
                                    </button>
                                </div>
                            </div>
                        @else
                            <form method="POST" action="{{ route('approver.assignments.decide', $activeAssignment) }}" class="flex flex-col sm:w-64 gap-2">
                                @csrf
                                <textarea name="comments" rows="1" placeholder="Optional comments…"
                                    class="w-full rounded-lg border-surface-300 text-xs focus:border-primary-500 focus:ring-primary-500 px-3 py-2"></textarea>
                                <div class="flex gap-2">
                                    <button type="submit" name="decision" value="approved"
                                        class="flex-1 bg-gradient-to-b from-approved-500 to-approved-600 hover:from-approved-600 hover:to-approved-700 text-white text-xs font-semibold py-2 rounded-lg shadow-sm transition-all">
                                        Approve
                                    </button>
                                    <button type="submit" name="decision" value="rejected"
                                        class="flex-1 bg-gradient-to-b from-rejected-500 to-rejected-600 hover:from-rejected-600 hover:to-rejected-700 text-white text-xs font-semibold py-2 rounded-lg shadow-sm transition-all">
                                        Reject
                                    </button>
                                </div>
                            </form>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@empty
    <div class="bg-white rounded-xl shadow-card border border-surface-200 p-12 text-center">
        <p class="text-sm text-surface-500">
            @if(request('document') || request('priority'))
                No pending documents match these filters.
            @else
                Your queue is clear — no pending documents right now.
            @endif
        </p>
    </div>
@endforelse
<div id="review-no-matches" class="hidden bg-white rounded-xl shadow-card border border-surface-200 p-12 text-center">
    <p class="text-sm text-surface-500">No documents on this page match "<span id="review-no-matches-term"></span>". Press Enter to search every page.</p>
</div>

@if($containers->hasPages())
    <div class="pt-2">{{ $containers->links() }}</div>
@endif
