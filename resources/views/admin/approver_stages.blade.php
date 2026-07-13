@extends('layouts.app')
@section('title', 'Manage Approver Stages')
@section('page-title', 'Stage Assignments — ' . $user->full_name)

@section('content')
<div class="max-w-xl mx-auto">
    <div class="bg-white rounded-xl shadow-card border border-surface-200 p-6">
        <p class="text-sm text-surface-600 mb-1">
            <span class="font-medium text-surface-800">{{ $user->full_name }}</span>
            &middot; <span class="text-xs text-surface-400">{{ $user->username }}</span>
        </p>
        <p class="text-xs text-surface-500 mb-6">
            Category: <span class="font-medium">{{ $user->assigned_category }}</span> (fixed at account creation).
            Select which stages within this category {{ $user->full_name }} should handle.
            Leave everything unchecked to keep them eligible for <strong>every</strong> stage in this category (default).
        </p>

        <form method="POST" action="{{ route('admin.users.stages.update', $user) }}" class="space-y-3">
            @csrf

            @forelse($stages as $stage)
                <label class="flex items-start gap-3 p-3 rounded-lg border border-surface-200 hover:bg-surface-50 cursor-pointer">
                    <input type="checkbox" name="stage_ids[]" value="{{ $stage->stage_id }}"
                        @checked(in_array($stage->stage_id, $assignedStageIds))
                        class="mt-0.5 rounded border-surface-300 text-primary-700 focus:ring-primary-500">
                    <span>
                        <span class="block text-sm font-medium text-surface-800">{{ $stage->sequence_order }}. {{ $stage->stage_name }}</span>
                        @if($stage->description)
                            <span class="block text-xs text-surface-400">{{ $stage->description }}</span>
                        @endif
                    </span>
                </label>
            @empty
                <p class="text-sm text-surface-400">No workflow stages are configured for this category yet — add some under Workflow Config first.</p>
            @endforelse

            <button class="w-full bg-primary-700 hover:bg-primary-800 text-white text-sm font-medium py-2.5 rounded-lg transition-colors mt-4">
                Save Stage Assignments
            </button>
        </form>

        <a href="{{ route('admin.users') }}" class="block text-center text-xs text-surface-500 hover:underline mt-4">&larr; Back to User Accounts</a>
    </div>
</div>
@endsection