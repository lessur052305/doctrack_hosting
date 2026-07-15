@props(['document'])

{{--
    Full workflow-stage pipeline for this document's category (Feature:
    show every stage, not just whichever one currently "pops up"). Every
    configured stage for the category is listed and its state derived from
    whatever assignment row (if any) exists for it:
      - no assignment row yet          -> "upcoming" (not yet reached)
      - assignment row, pending        -> "pending"
      - assignment row, approved/etc.  -> "approved"/"rejected"/"auto_approved"

    Highlighting (Feature: approver can see which stages are theirs at a
    glance): when the viewer is an approver, their own pending stage(s) for
    this document are highlighted in primary blue instead of the neutral
    orange used for other approvers' pending stages. If they hold more than
    one stage on this document, only the earliest (lowest sequence_order)
    is marked "Your turn" — that's the one the action button elsewhere on
    the page acts on. Their other stage(s) are marked "Up next" — visible,
    but not yet actionable until the current one resolves.
--}}
@php
    $allStages = \App\Models\WorkflowStage::forCategory($document->ml_category)->get();
    $assignmentsByStage = $document->assignments->keyBy('stage_id');
    $currentUserId = auth()->id();

    $myActiveStageId = $document->assignments
        ->where('individual_status', 'pending')
        ->where('user_id', $currentUserId)
        ->sortBy(fn ($a) => $a->stage->sequence_order)
        ->pluck('stage_id')
        ->first();
@endphp

<div class="space-y-2">
    @foreach($allStages as $stage)
        @php
            $assignment = $assignmentsByStage->get($stage->stage_id);
            $state = $assignment ? $assignment->individual_status : 'upcoming'; // pending|approved|rejected|auto_approved|upcoming
            $isMine = $state === 'pending' && $assignment->user_id === $currentUserId;
            $isMyActive = $isMine && $stage->stage_id === $myActiveStageId;
        @endphp
        <div class="flex items-center gap-3 rounded-lg border p-3
            @if($isMyActive) border-primary-500 bg-primary-50 ring-1 ring-inset ring-primary-500/40
            @elseif($isMine) border-primary-200 bg-primary-50/40
            @elseif($state === 'pending') border-processing-500/30 bg-processing-50/40
            @else border-surface-200 @endif">
            <div class="w-6 h-6 rounded-full flex items-center justify-center text-[11px] font-bold flex-shrink-0
                @if(in_array($state, ['approved', 'auto_approved'])) bg-approved-500 text-white
                @elseif($state === 'rejected') bg-rejected-500 text-white
                @elseif($isMyActive) bg-primary-600 text-white
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
                <p class="text-xs font-medium text-surface-800 flex items-center gap-1.5 flex-wrap">
                    {{ $stage->stage_name }}
                    @if($isMyActive)
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold text-gray-600">Your turn</span>
                    @elseif($isMine)
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-primary-100 text-primary-700">Up next</span>
                    @endif
                </p>
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