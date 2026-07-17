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
                    <label class="block text-xs font-medium text-surface-700 mb-1">Temporary Password</label>
                    <input type="password" name="password" required minlength="8" class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                </div>
                <button class="w-full bg-primary-700 hover:bg-primary-800 text-white text-sm font-medium py-2.5 rounded-lg transition-colors">Create Account</button>
            </form>
        </div>
    </div>

    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full min-w-[720px] text-sm">
                <thead class="bg-surface-50 text-surface-500 text-xs uppercase tracking-wide">
                    <tr>
                        <th class="text-left px-6 py-3 font-medium">Account ID</th>
                        <th class="text-left px-6 py-3 font-medium">Name</th>
                        <th class="text-left px-6 py-3 font-medium">Role</th>
                        <th class="text-left px-6 py-3 font-medium">Category / Stages</th>
                        <th class="text-left px-6 py-3 font-medium">Status</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-surface-100">
                    @foreach($users as $u)
                        <tr class="hover:bg-surface-50">
                            <td class="px-6 py-3 text-surface-500">#{{ $u->user_id }}</td>
                            <td class="px-6 py-3">
                                <p class="font-medium text-surface-800">{{ $u->full_name }}</p>
                                <p class="text-xs text-surface-400">{{ $u->username }} &middot; {{ $u->email }}</p>
                            </td>
                            <td class="px-6 py-3 capitalize text-surface-600">
                                {{ $u->role }}
                                @if($u->role === 'approver')
                                    <br>
                                    <span class="text-xs font-medium {{ $u->is_busy ? 'text-processing-700' : 'text-approved-700' }}">
                                        {{ $u->is_busy ? 'Busy/Away' : 'Available' }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-3">
                                @if($u->role === 'admin')
                                    <span class="text-xs text-surface-400">All categories</span>
                                @elseif($u->role === 'approver')
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-primary-50 text-primary-700 ring-1 ring-inset ring-primary-500/20">
                                        {{ $u->assigned_category ?? 'Unassigned' }}
                                    </span>
                                    <p class="text-xs text-surface-400 mt-1">
                                        @if($u->workflowStages->isEmpty())
                                            All stages
                                        @else
                                            {{ $u->workflowStages->pluck('stage_name')->implode(', ') }}
                                        @endif
                                    </p>
                                @else
                                    <span class="text-xs text-surface-400">Any category (own submissions)</span>
                                @endif
                            </td>
                            <td class="px-6 py-3">
                                <span class="text-xs font-medium {{ $u->is_active ? 'text-approved-700' : 'text-rejected-700' }}">
                                    {{ $u->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-6 py-3 text-right space-x-2 whitespace-nowrap">
                                @if($u->role === 'approver')
                                    <a href="{{ route('admin.users.stages.edit', $u) }}" class="text-xs font-medium text-primary-700 hover:underline">Manage Category & Stages</a>
                                @endif
                                <form method="POST" action="{{ route('admin.users.toggle', $u) }}" class="inline">
                                    @csrf
                                    <button class="text-xs font-medium text-primary-700 hover:underline">
                                        {{ $u->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            <p class="px-6 py-2 text-xs text-surface-400 border-t border-surface-200 bg-surface-50">
                An approver's category and specific stage assignments can be changed anytime via "Manage Category & Stages" — this only affects future document routing, never assignments they already hold. Originators are never category-restricted.
            </p>
            <div class="px-6 py-4 border-t border-surface-200">{{ $users->links() }}</div>
        </div>
    </div>
</div>
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