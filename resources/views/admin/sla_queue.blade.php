@extends('layouts.app')
@section('title', 'SLA Overrides')
@section('page-title', 'SLA Override Queue')

@section('content')
<div class="space-y-6">
    <div class="rounded-lg bg-processing-50 border border-processing-500/30 text-processing-700 px-4 py-3 text-xs">
        These assignments breached their SLA deadline and were escalated for Admin review. If left unresolved for the configured grace period, the system will auto-approve them and notify all Admins &amp; Approvers.
    </div>

    @forelse($assignments as $container)
        @php $docCount = $container->documents->count(); @endphp
        <div class="rounded-xl border {{ $container->is_batch ? 'border-primary-200' : 'border-rejected-500/20' }} bg-white shadow-card overflow-hidden">

            {{-- Batch header — only shown when 2+ documents were submitted together --}}
            @if($container->is_batch)
                <div class="px-6 py-3 bg-primary-100/60 border-b border-primary-200 flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-primary-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        <span class="text-xs font-semibold text-primary-800">Submitted together — {{ $docCount }} documents</span>
                        <span class="text-xs text-surface-500">by {{ $container->originator->full_name }}</span>
                    </div>
                    <div class="text-xs font-medium text-rejected-700">
                        Due {{ $container->due_date?->format('M j, Y g:i A') ?? '—' }}
                    </div>
                </div>
            @endif

            <div class="divide-y divide-surface-100">
                @foreach($container->documents as $documentId => $stageAssignments)
                    @php
                        $doc = $stageAssignments->first()->document;
                        $activeAssignment = $stageAssignments->sortBy(fn ($a) => $a->stage->sequence_order)->first();
                    @endphp
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-2">
                            @if($activeAssignment->escalation_reason === 'no_eligible_approver')
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-processing-50 text-processing-700 ring-1 ring-inset ring-processing-500/20">No Approver Available</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-rejected-50 text-rejected-700 ring-1 ring-inset ring-rejected-500/20">SLA Breached</span>
                            @endif
                            @if(!$container->is_batch)
                                <span class="text-xs font-medium text-rejected-700">Due {{ $container->due_date?->format('M j, Y g:i A') ?? '—' }}</span>
                            @endif
                        </div>
                        <h3 class="text-sm font-semibold text-surface-900 mb-1">{{ $doc->title }}</h3>
                        <p class="text-xs text-surface-500 mb-3">
                            Category: {{ $doc->ml_category }} · Submitted by {{ $container->originator->full_name }} ·
                            <button type="button"
                                onclick="openDocumentViewer('{{ route('documents.file', $doc) }}', '{{ $doc->mime_type }}', '{{ addslashes($doc->original_filename ?? $doc->title) }}')"
                                class="text-primary-700 hover:underline font-medium">View original file</button>
                        </p>

                        {{-- Full stage pipeline for this document's category, so admins can see
                             what already happened and what's still to come — not just whichever
                             breached stage currently needs a decision. Only the earliest
                             (lowest sequence_order) breached stage is highlighted and actionable
                             below; once it's resolved, the next breached stage (if any) surfaces
                             the same way on reload. --}}
                        <div class="mb-4">
                            <x-workflow-stage-list :document="$doc" :highlight-stage-id="$activeAssignment->stage_id" />
                        </div>

                        <div class="flex flex-col sm:flex-row sm:items-center gap-4 rounded-lg border border-rejected-500/30 bg-rejected-50/40 p-4">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-1">
                                    @if($activeAssignment->escalation_reason === 'no_eligible_approver')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold ring-1 ring-inset bg-processing-50 text-processing-700 ring-processing-500/20">No Approver Available</span>
                                    @else
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold ring-1 ring-inset bg-rejected-50 text-rejected-700 ring-rejected-500/20">SLA Breached</span>
                                    @endif
                                    <span class="text-xs text-surface-500 font-medium">Stage: {{ $activeAssignment->stage->stage_name }}</span>
                                </div>
                                <p class="text-xs text-surface-500">
                                    Approver: {{ $activeAssignment->approver->full_name ?? 'Unassigned' }} &middot;
                                    @if($activeAssignment->escalation_reason === 'no_eligible_approver')
                                        Escalated immediately — no eligible replacement after deactivation &middot;
                                        Original deadline <span data-live-time="{{ $activeAssignment->sla_expires_at->timestamp }}">{{ $activeAssignment->sla_expires_at->diffForHumans() }}</span>
                                    @else
                                        Expired <span data-live-time="{{ $activeAssignment->sla_expires_at->timestamp }}">{{ $activeAssignment->sla_expires_at->diffForHumans() }}</span>
                                    @endif
                                    @if($activeAssignment->adminGraceExpiresAt())
                                        &middot; <span data-live-time="{{ $activeAssignment->adminGraceExpiresAt()->timestamp }}" data-live-urgent-under="7200" class="font-medium">{{ $activeAssignment->adminGraceExpiresAt()->diffForHumans() }}</span> until system auto-approval
                                    @endif
                                </p>
                            </div>

                            <form method="POST" action="{{ route('admin.sla.override', $activeAssignment) }}" class="flex flex-col sm:w-64 gap-2">
                                @csrf
                                <textarea name="comments" rows="1" placeholder="Override reason (optional)…"
                                    class="w-full rounded-lg border-surface-300 text-xs focus:border-primary-500 focus:ring-primary-500 px-3 py-2"></textarea>
                                <div class="flex gap-2">
                                    <button type="submit" name="decision" value="approved"
                                        class="flex-1 bg-approved-500 hover:bg-approved-700 text-white text-xs font-semibold py-2 rounded-lg transition-colors">
                                        Approve
                                    </button>
                                    <button type="submit" name="decision" value="rejected"
                                        class="flex-1 bg-rejected-500 hover:bg-rejected-700 text-white text-xs font-semibold py-2 rounded-lg transition-colors">
                                        Reject
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-12 text-center">
            <p class="text-sm text-surface-500">No SLA breaches pending override.</p>
        </div>
    @endforelse

    @if($assignments->hasPages())
        <div>{{ $assignments->links() }}</div>
    @endif

    @if($reviewContainers->isNotEmpty())
        <div class="pt-4">
            <h2 class="text-sm font-semibold text-surface-900 mb-1">Auto-Approved — Awaiting Review</h2>
            <p class="text-xs text-surface-500 mb-4">
                These documents had one or more stages auto-approved by the system after no one acted in time. Confirm if everything looks fine, or dispute if you find a problem — disputing flags the whole document and asks the originator to resubmit; it does not reverse the approval(s).
            </p>

            <div class="space-y-4">
                @foreach($reviewContainers as $container)
                    @php $doc = $container->document; @endphp
                    <div class="rounded-xl border border-processing-500/20 bg-white shadow-card overflow-hidden">
                        <div class="p-6">
                            <h3 class="text-sm font-semibold text-surface-900 mb-1">{{ $doc->title }}</h3>
                            <p class="text-xs text-surface-500 mb-3">
                                Category: {{ $doc->ml_category }} &middot;
                                Uploaded {{ $doc->upload_date?->format('M j, Y g:i A') ?? '—' }} &middot;
                                Due {{ $doc->due_date?->format('M j, Y g:i A') ?? '—' }} &middot;
                                <button type="button"
                                    onclick="openDocumentViewer('{{ route('documents.file', $doc) }}', '{{ $doc->mime_type }}', '{{ addslashes($doc->original_filename ?? $doc->title) }}')"
                                    class="text-primary-700 hover:underline font-medium">View original file</button>
                            </p>

                            <div class="mb-4">
                                <x-workflow-stage-list :document="$doc" />
                            </div>

                            <ul class="mb-4 divide-y divide-surface-100 border border-surface-100 rounded-lg overflow-hidden">
                                @foreach($container->assignments as $reviewAssignment)
                                    <li class="px-4 py-2.5 bg-surface-50/50">
                                        <p class="text-xs font-medium text-surface-800">{{ $reviewAssignment->stage->stage_name }}</p>
                                        <p class="text-xs text-surface-500">
                                            Was assigned to: {{ $reviewAssignment->approver->full_name ?? 'Unassigned' }} &middot;
                                            SLA breached <span data-live-time="{{ optional($reviewAssignment->sla_expires_at)->timestamp }}">{{ optional($reviewAssignment->sla_expires_at)->diffForHumans() }}</span> &middot;
                                            Auto-approved <span data-live-time="{{ optional($reviewAssignment->acted_at)->timestamp }}">{{ optional($reviewAssignment->acted_at)->diffForHumans() }}</span>
                                        </p>
                                    </li>
                                @endforeach
                            </ul>

                            <form method="POST" action="{{ route('admin.sla.review', $doc) }}" class="space-y-3">
                                @csrf
                                <textarea name="note" rows="2" placeholder="Note (required if disputing)…"
                                    class="w-full rounded-lg border-surface-300 text-xs focus:border-primary-500 focus:ring-primary-500 px-4 py-3"></textarea>
                                <div class="flex justify-end gap-3">
                                    <button type="submit" name="outcome" value="confirmed"
                                        class="bg-approved-500 hover:bg-approved-700 text-white text-xs font-semibold px-6 py-2.5 rounded-lg transition-colors">
                                        Confirm
                                    </button>
                                    <button type="submit" name="outcome" value="disputed"
                                        class="bg-rejected-500 hover:bg-rejected-700 text-white text-xs font-semibold px-6 py-2.5 rounded-lg transition-colors">
                                        Dispute
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
@endsection