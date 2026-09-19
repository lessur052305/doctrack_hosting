{{--
    Top-right header identity block, shared by every role via
    layouts/app.blade.php — replaces what used to be a single role pill.

    Department / category / stage only exist for approvers (see the
    add_department_and_level_to_users_table migration's docblock — both
    columns are approver-specific and null for admin/originator), so that
    line is only ever shown for the approver role; other roles just get
    their name and a role pill, same information the old single pill gave,
    just paired with the name now.
--}}
@php
    $user = auth()->user();
    $isApprover = $user->role === 'approver';

    if ($isApprover) {
        // Mirrors DocumentController::stagesLabelFor()'s "empty ->
        // eligible for every stage in my category" reasoning — an empty
        // workflowStages() relation means unrestricted, not "handles no
        // stages," so that has to read as "All Stages," not blank.
        $stageNames = $user->workflowStages()->orderBy('sequence_order')->pluck('stage_name');
        $stageLabel = $stageNames->isNotEmpty() ? $stageNames->implode(' / ') : 'All Stages';
        $positionLabel = match ($user->level) {
            'head' => 'Head Approver',
            'staff' => 'Staff Approver',
            default => 'Approver',
        };
    }
@endphp
<div class="text-center leading-tight">
    @if($isApprover)
        <p class="text-xs text-surface-500 truncate max-w-[140px] sm:max-w-[260px]">
            {{ $user->department ?? '—' }} | {{ $user->assigned_category ?? '—' }} | {{ $stageLabel }}
        </p>
    @endif
    <p class="text-sm font-semibold text-surface-900 truncate max-w-[140px] sm:max-w-[260px]">{{ $user->full_name }}</p>
    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-primary-50 text-primary-700 ring-1 ring-inset ring-primary-500/15">
        {{ $isApprover ? $positionLabel : ucfirst($user->role) }}
    </span>
</div>
