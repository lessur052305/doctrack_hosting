@props(['document'])

{{--
    Full workflow-stage pipeline for this document's category (Feature:
    show every stage, not just whichever one currently "pops up"). With
    single-assignment, just-in-time routing, a DocumentAssignment row for
    a later stage doesn't exist until the prior stage is resolved — so
    without this list, approvers/admins/originators only ever see the one
    stage currently pending and have no visibility into the stages before
    or after it. This resolves that by listing every configured stage for
    the category and deriving each one's state from whatever assignment
    row (if any) exists for it:
      - no assignment row yet          -> "upcoming" (not yet reached)
      - assignment row, pending        -> "current"
      - assignment row, approved/etc.  -> "done" (approved/rejected/auto_approved)
--}}
@php
    $allStages = \App\Models\WorkflowStage::forCategory($document->ml_category)->get();
    $assignmentsByStage = $document->assignments->keyBy('stage_id');
@endphp

<div class="space-y-2">
    @foreach($allStages as $stage)
        @php
            $assignment = $assignmentsByStage->get($stage->stage_id);
            $state = $assignment ? $assignment->individual_status : 'upcoming'; // pending|approved|rejected|auto_approved|upcoming
        @endphp
        <div class="flex items-center gap-3 rounded-lg border p-3
            {{ $state === 'pending' ? 'border-processing-500/30 bg-processing-50/40' : 'border-surface-200' }}">
            <div class="w-6 h-6 rounded-full flex items-center justify-center text-[11px] font-bold flex-shrink-0
                @if(in_array($state, ['approved', 'auto_approved'])) bg-approved-500 text-white
                @elseif($state === 'rejected') bg-rejected-500 text-white
                @elseif($state === 'pending') bg-processing-500 text-white
                @else bg-surface-200 text-surface-400 @endif">
                @if(in_array($state, ['approved', 'auto_approved']))
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                @elseif($state === 'rejected')
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                @else
                    {{ $stage->sequence_order }}
                @endif
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-xs font-medium text-surface-800">{{ $stage->stage_name }}</p>
                <p class="text-[11px] text-surface-400">
                    @if($state === 'upcoming')
                        Not yet reached
                    @elseif($state === 'pending')
                        Awaiting decision{{ $assignment->approver ? ' · ' . $assignment->approver->full_name : '' }}
                    @else
                        {{ $state === 'auto_approved' ? 'Auto-approved' : ucfirst($state) }}{{ $assignment->approver ? ' by ' . $assignment->approver->full_name : '' }}
                        @if($assignment->acted_at) &middot; {{ $assignment->acted_at->diffForHumans() }} @endif
                    @endif
                </p>
                @if($assignment && $assignment->comments)
                    <p class="text-[11px] text-surface-500 mt-0.5 italic">"{{ $assignment->comments }}"</p>
                @endif
            </div>
        </div>
    @endforeach
</div>