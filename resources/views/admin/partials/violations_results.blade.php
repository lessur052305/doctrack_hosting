{{--
    Extracted from sla_violations.blade.php so AdminController::
    violationsRefresh() can return exactly this fragment for the live-search
    JS to swap in. Expects: $violations.
--}}
@php
    // Nest by document — a document can rack up several breaches (one per
    // stage, or repeat breaches over time), which previously showed as
    // separate flat rows repeating the same title and made the report
    // noisy. Same pattern as the Admin dashboard's "SLA Override Alerts"
    // widget.
    $violationsByDocument = $violations->getCollection()->groupBy('document_id');
@endphp

<div class="divide-y divide-surface-100">
    @forelse($violationsByDocument as $docViolations)
        @php
            $doc = $docViolations->first()->document;
            $latest = $docViolations->first(); // already ordered newest-first from the query
            $count = $docViolations->count();
        @endphp
        <details class="group">
            <summary class="list-none [&::-webkit-details-marker]:hidden cursor-pointer px-6 py-4 hover:bg-surface-50/60 flex items-center justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <svg class="w-4 h-4 text-surface-400 shrink-0 transition-transform group-open:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                    </svg>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-surface-900 truncate">{{ $doc->title ?? 'Deleted document' }}</p>
                        <p class="text-xs text-surface-400 truncate">
                            {{ $doc?->ml_category }}
                            @if($doc?->upload_date) · Uploaded {{ $doc->upload_date->format('M j, Y g:i A') }}@endif
                            @if($doc?->due_date)
                                · Due {{ $doc->due_date->format('M j, Y g:i A') }}
                                @if($doc?->upload_date)
                                    <span class="{{ $doc->upload_date->diffInMinutes($doc->due_date) <= 60 ? 'text-rejected-500 font-medium' : '' }}">({{ $doc->upload_date->diffForHumans($doc->due_date, true) }} turnaround)</span>
                                @endif
                            @endif
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-3 shrink-0">
                    <span class="hidden sm:inline text-xs text-surface-400">Latest
                        <span data-live-time="{{ $latest->violation_timestamp->timestamp }}">{{ $latest->violation_timestamp->diffForHumans() }}</span>
                    </span>
                    @if($doc)
                        {{-- Status of the document RIGHT NOW, not at breach time — a
                             violation here is a historical log entry, so the document
                             may well already be resolved since. This is the single most
                             useful signal for triage: still needs attention vs. handled. --}}
                        <x-status-badge :status="$doc->display_status" />
                    @endif
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rejected-50 text-rejected-700 ring-1 ring-inset ring-rejected-500/20">
                        {{ $count }} breach{{ $count === 1 ? '' : 'es' }}
                    </span>
                </div>
            </summary>

            <div class="px-6 pb-4">
                <div class="hidden sm:grid grid-cols-[minmax(150px,170px)_1fr_1fr_minmax(150px,170px)] gap-4 px-3 py-1.5 text-surface-400 text-[11px] font-semibold uppercase tracking-wide">
                    <span>Breached</span>
                    <span>Stage</span>
                    <span>Approver</span>
                    <span>Resolved</span>
                </div>
                <div class="space-y-1">
                    @foreach($docViolations as $v)
                        @php
                            // The exact deadline that was crossed (sla_expires_at) is more
                            // precise than violation_timestamp, which only records when the
                            // periodic sweep happened to notice — fall back to it for
                            // violations logged before assignment_id was linked.
                            $breachedAt = $v->assignment->sla_expires_at ?? $v->violation_timestamp;
                            $resolvedStatus = $v->assignment->individual_status ?? null;
                        @endphp
                        <div class="grid grid-cols-2 sm:grid-cols-[minmax(150px,170px)_1fr_1fr_minmax(150px,170px)] gap-x-4 gap-y-0.5 text-xs py-2 px-3 rounded-lg odd:bg-surface-50/50">
                            <span class="col-span-2 sm:col-span-1 text-surface-500 whitespace-nowrap">{{ $breachedAt->format('M j, Y g:i A') }}</span>
                            <span class="text-surface-700 font-medium truncate">{{ $v->stage_name }}</span>
                            <span class="text-surface-600 truncate">{{ $v->approver->full_name ?? 'Unassigned' }}</span>
                            <span class="col-span-2 sm:col-span-1">
                                @if($resolvedStatus === null)
                                    <span class="text-surface-400">—</span>
                                @elseif($resolvedStatus === 'pending')
                                    <span class="text-processing-700 font-medium">Still pending</span>
                                @else
                                    @php
                                        $a = $v->assignment;
                                        // Once escalated, the original approver can no longer
                                        // act (see ApprovalController's 409 guard) — so a
                                        // resolved breach was always either an Admin override
                                        // or the system's own grace-window auto-approval.
                                        $resolvedBy = $a->auto_approved
                                            ? 'Auto-approved by system'
                                            : ($a->admin_override_by ? 'Admin override: ' . ($a->adminOverrideBy->full_name ?? 'Admin') : ($a->approver->full_name ?? 'Unknown'));
                                    @endphp
                                    <span class="{{ $resolvedStatus === 'rejected' ? 'text-rejected-700' : 'text-approved-700' }} font-medium whitespace-nowrap block">
                                        {{ $a->acted_at?->format('M j, Y g:i A') ?? '—' }}
                                    </span>
                                    <span class="text-surface-400 text-[11px]">{{ $resolvedBy }}</span>
                                @endif
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        </details>
    @empty
        <div class="px-6 py-8 text-center text-surface-400 text-sm">No SLA violations match these filters.</div>
    @endforelse
</div>
<div class="px-6 py-4 border-t border-surface-200">{{ $violations->links() }}</div>
