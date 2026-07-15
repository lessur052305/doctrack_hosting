@extends('layouts.app')
@section('title', 'Review Queue')
@section('page-title', 'Review Queue')

@section('content')
<div class="space-y-6">
    @forelse($containers as $container)
        @php
            $docCount = $container->documents->count();
        @endphp
        <div class="rounded-xl border {{ $container->is_batch ? 'border-primary-200 bg-primary-50/30' : 'border-surface-200 bg-white' }} shadow-card overflow-hidden">

            {{-- Batch header — only shown when 2+ documents were submitted together --}}
            @if($container->is_batch)
                <div class="px-6 py-3 bg-primary-100/60 border-b border-primary-200 flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-primary-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
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
                            [$pLabel, $pClass] = $priorityMap[$activeAssignment->priority_rank] ?? $priorityMap[2];
                        @endphp
                        <div class="flex flex-col sm:flex-row sm:items-center gap-4 rounded-lg border border-primary-200 bg-primary-50/40 p-4">
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
                            </div>

                            <form method="POST" action="{{ route('approver.assignments.decide', $activeAssignment) }}" class="flex flex-col sm:w-64 gap-2">
                                @csrf
                                <textarea name="comments" rows="1" placeholder="Optional comments…"
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
            <p class="text-sm text-surface-500">Your queue is clear — no pending documents right now.</p>
        </div>
    @endforelse

    @if($containers->hasPages())
        <div class="pt-2">{{ $containers->links() }}</div>
    @endif
</div>
@endsection