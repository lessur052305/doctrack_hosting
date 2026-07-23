@extends('layouts.app')
@section('title', 'User Accounts')
@section('page-title', 'User Accounts')

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-6">
            <h2 class="text-sm font-semibold text-surface-900 mb-4">Create Account</h2>
            <form method="POST" action="{{ route('admin.users.store') }}" class="space-y-3">
                @csrf
                <div>
                    <label class="block text-xs font-medium text-surface-700 mb-1">Full Name</label>
                    <input name="full_name" required class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-surface-700 mb-1">Username</label>
                    <input name="username" required class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-surface-700 mb-1">Email</label>
                    <input type="email" name="email" required class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-surface-700 mb-1">Role</label>
                    <select name="role" id="create-role" required class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                        <option value="originator">Staff (Originator)</option>
                        <option value="approver">Staff (Approver)</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div id="create-category-field">
                    <label class="block text-xs font-medium text-surface-700 mb-1">
                        Assigned Category
                        <span class="text-surface-400 font-normal">(Approvers only — changeable later via "Manage Category & Stages")</span>
                    </label>
                    <select name="assigned_category" id="create-category" class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                        @foreach(\App\Services\ValidationService::knownCategories() as $c)
                            <option value="{{ $c }}">{{ $c }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Stage picker: one checkbox-group per category, JS shows only the group matching the selected category --}}
                <div id="create-stages-field">
                    <label class="block text-xs font-medium text-surface-700 mb-1">
                        Specific Stages <span class="text-surface-400 font-normal">(optional — leave all unchecked for every stage in this category)</span>
                    </label>
                    @foreach($stagesByCategory as $category => $categoryStages)
                        <div class="stage-group space-y-1 {{ !$loop->first ? 'hidden' : '' }}" data-category="{{ $category }}">
                            @forelse($categoryStages as $stage)
                                <label class="flex items-center gap-2 text-xs text-surface-600">
                                    <input type="checkbox" name="stage_ids[]" value="{{ $stage->stage_id }}" class="rounded border-surface-300 text-primary-700 focus:ring-primary-500">
                                    {{ $stage->sequence_order }}. {{ $stage->stage_name }}
                                </label>
                            @empty
                                <p class="text-xs text-surface-400">No stages configured for {{ $category }} yet.</p>
                            @endforelse
                        </div>
                    @endforeach
                </div>

                <div>
                    <label class="block text-xs font-medium text-surface-700 mb-1">Password</label>
                    <input type="password" name="password" required minlength="8" class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                </div>
                <button class="w-full bg-primary-700 hover:bg-primary-800 text-white text-sm font-medium py-2.5 rounded-lg transition-colors">Create Account</button>
            </form>
        </div>
    </div>

    <div class="lg:col-span-2" id="users-table"
        data-refresh-url="{{ route('admin.users.refresh') }}"
        data-poll-url="{{ route('admin.users.poll') }}">
        @include('admin.partials.users_table')
    </div>
</div>

<script>
    // Same live-update pattern used elsewhere (see admin/dashboard.blade.php,
    // ml_training.blade.php) — pushes the "Unverified" badge/Resend action
    // away the instant an admin (or anyone else watching this page) sees
    // someone actually click their verification link, not on next reload.
    document.addEventListener('DOMContentLoaded', function () {
        const tableEl = document.getElementById('users-table');
        if (!tableEl) return;

        const opts = {
            refreshUrl: tableEl.dataset.refreshUrl,
            target: tableEl,
            preserveQueryString: true, // keep ?show_inactive=1 across a live swap
        };

        startLiveChannel('admin-dashboard', '.user.verified', opts);
        startLivePoll({ ...opts, pollUrl: tableEl.dataset.pollUrl });
    });
</script>
@endsection

@push('scripts')
<script>
    const roleSelect = document.getElementById('create-role');
    const categoryField = document.getElementById('create-category-field');
    const stagesField = document.getElementById('create-stages-field');
    const categorySelect = document.getElementById('create-category');
    const stageGroups = stagesField.querySelectorAll('.stage-group');

    const toggleApproverFields = () => {
        const show = roleSelect.value === 'approver';
        categoryField.style.display = show ? 'block' : 'none';
        stagesField.style.display = show ? 'block' : 'none';
    };

    const showStagesForCategory = () => {
        stageGroups.forEach(group => {
            group.classList.toggle('hidden', group.dataset.category !== categorySelect.value);
        });
    };

    roleSelect.addEventListener('change', toggleApproverFields);
    categorySelect.addEventListener('change', showStagesForCategory);
    toggleApproverFields();
    showStagesForCategory();
</script>
@endpush