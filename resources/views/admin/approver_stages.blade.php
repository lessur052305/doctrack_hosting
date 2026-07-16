@extends('layouts.app')
@section('title', 'Manage Approver Category & Stages')
@section('page-title', 'Category & Stage Assignments — ' . $user->full_name)

@section('content')
<div class="max-w-xl mx-auto">
    <div class="bg-white rounded-xl shadow-card border border-surface-200 p-6">
        <p class="text-sm text-surface-600 mb-1">
            <span class="font-medium text-surface-800">{{ $user->full_name }}</span>
            &middot; <span class="text-xs text-surface-400">{{ $user->username }}</span>
        </p>
        <p class="text-xs text-surface-500 mb-4">
            Changing category resets stage picks to "every stage in the new category" — old stage picks
            wouldn't make sense there. Leave everything unchecked to keep {{ $user->full_name }} eligible
            for <strong>every</strong> stage in whichever category is selected (default).
        </p>

        @if($pendingInOldCategory > 0)
            <div class="mb-4 rounded-lg bg-processing-50 border border-processing-500/30 text-processing-700 px-4 py-3 text-xs">
                {{ $user->full_name }} currently has <strong>{{ $pendingInOldCategory }}</strong> pending
                assignment(s) in their queue. Reassigning category/stages does <strong>not</strong> affect
                these — they stay in the queue exactly as-is and can still be decided normally.
            </div>
        @endif

        <form method="POST" action="{{ route('admin.users.stages.update', $user) }}" class="space-y-4">
            @csrf

            <div>
                <label class="block text-xs font-medium text-surface-700 mb-1">Category</label>
                <select name="assigned_category" id="edit-category" required
                    class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                    @foreach(\App\Services\ValidationService::knownCategories() as $c)
                        <option value="{{ $c }}" @selected($user->assigned_category === $c)>{{ $c }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-xs font-medium text-surface-700 mb-1">
                    Specific Stages <span class="text-surface-400 font-normal">(optional — leave all unchecked for every stage)</span>
                </label>
                @foreach($stagesByCategory as $category => $categoryStages)
                    <div class="stage-group space-y-1 {{ $category !== $user->assigned_category ? 'hidden' : '' }}" data-category="{{ $category }}">
                        @forelse($categoryStages as $stage)
                            <label class="flex items-start gap-3 p-3 rounded-lg border border-surface-200 hover:bg-surface-50 cursor-pointer">
                                <input type="checkbox" name="stage_ids[]" value="{{ $stage->stage_id }}"
                                    @checked($category === $user->assigned_category && in_array($stage->stage_id, $assignedStageIds))
                                    class="mt-0.5 rounded border-surface-300 text-primary-700 focus:ring-primary-500">
                                <span>
                                    <span class="block text-sm font-medium text-surface-800">{{ $stage->sequence_order }}. {{ $stage->stage_name }}</span>
                                    @if($stage->description)
                                        <span class="block text-xs text-surface-400">{{ $stage->description }}</span>
                                    @endif
                                </span>
                            </label>
                        @empty
                            <p class="text-xs text-surface-400">No stages configured for {{ $category }} yet.</p>
                        @endforelse
                    </div>
                @endforeach
            </div>

            <button class="w-full bg-primary-700 hover:bg-primary-800 text-white text-sm font-medium py-2.5 rounded-lg transition-colors">
                Save
            </button>
        </form>

        <a href="{{ route('admin.users') }}" class="block text-center text-xs text-surface-500 hover:underline mt-4">&larr; Back to User Accounts</a>
    </div>
</div>

<script>
    // Switching category client-side just shows the matching stage-group
    // and unchecks the rest (they belong to a different category and
    // won't be submitted anyway since they're hidden .stage-group inputs
    // outside the selected group — but unchecking keeps the UI honest if
    // the admin flips back and forth before submitting).
    (function () {
        const categorySelect = document.getElementById('edit-category');
        const stageGroups = document.querySelectorAll('.stage-group');

        categorySelect.addEventListener('change', () => {
            stageGroups.forEach((group) => {
                const isMatch = group.dataset.category === categorySelect.value;
                group.classList.toggle('hidden', !isMatch);
                if (!isMatch) {
                    group.querySelectorAll('input[type=checkbox]').forEach((cb) => { cb.checked = false; });
                }
            });
        });
    })();
</script>
@endsection
