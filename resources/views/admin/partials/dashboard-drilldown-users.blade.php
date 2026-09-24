{{-- KPI card drill-down: active user list fragment, fetched by openKpiDrilldown() (see components/kpi-drilldown-modal.blade.php) --}}
<div class="overflow-y-auto">
    <table class="w-full text-sm">
        <thead class="bg-white sticky top-0 border-b border-surface-200">
            <tr class="text-left text-xs text-surface-500 font-medium">
                <th class="px-6 py-2">Name</th>
                <th class="px-4 py-2">Role</th>
                <th class="px-4 py-2">Category</th>
                <th class="px-4 py-2">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-surface-100">
            @forelse($users as $user)
                <tr class="hover:bg-surface-50/60">
                    <td class="px-6 py-2.5 font-medium text-surface-800">{{ $user->full_name }}</td>
                    <td class="px-4 py-2.5 text-surface-600 capitalize">{{ $user->role }}</td>
                    <td class="px-4 py-2.5 text-surface-600">{{ $user->assigned_category ?? 'All' }}</td>
                    <td class="px-4 py-2.5">
                        @if($user->role === 'approver')
                            <span class="text-xs font-medium {{ $user->isAvailable() ? 'text-approved-700' : 'text-surface-400' }}">{{ $user->isAvailable() ? 'Available' : 'Not Available' }}</span>
                        @else
                            <span class="text-xs text-surface-400">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-6 py-10 text-center text-sm text-surface-400">No active users.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
