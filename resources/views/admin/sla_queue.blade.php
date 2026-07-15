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
                    @php $doc = $stageAssignments->first()->document; @endphp
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-2">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-rejected-50 text-rejected-700 ring-1 ring-inset ring-rejected-500/20">SLA Breached</span>
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

                        <div class="mb-4">
                            <x-workflow-stage-list :document="$doc" />
                        </div>

                        <div class="space-y-3">
                            @foreach($stageAssignments as $a)
                                <div class="flex flex-col sm:flex-row gap-4 rounded-lg border border-rejected-500/20 p-4">
                                    <div class="flex-1 min-w-0">
                                        <p class="text-xs text-surface-500">
                                            Stage: {{ $a->stage->stage_name }} &middot;
                                            Approver: {{ $a->approver->full_name ?? 'Unassigned' }} &middot;
                                            Expired <span data-live-time="{{ $a->sla_expires_at->timestamp }}">{{ $a->sla_expires_at->diffForHumans() }}</span>
                                        </p>
                                    </div>
                                    <form method="POST" action="{{ route('admin.sla.override', $a) }}" class="flex flex-col sm:w-72 gap-2">
                                        @csrf
                                        <textarea name="comments" rows="1" placeholder="Override reason (optional)…"
                                            class="w-full rounded-lg border-surface-300 text-xs focus:border-primary-500 focus:ring-primary-500 px-3 py-2"></textarea>
                                        <div class="flex gap-2">
                                            <button name="decision" value="approved" class="flex-1 bg-approved-500 hover:bg-approved-700 text-white text-xs font-semibold py-2 rounded-lg">Override: Approve</button>
                                            <button name="decision" value="rejected" class="flex-1 bg-rejected-500 hover:bg-rejected-700 text-white text-xs font-semibold py-2 rounded-lg">Override: Reject</button>
                                        </div>
                                    </form>
                                </div>
                            @endforeach
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
</div>
@endsection