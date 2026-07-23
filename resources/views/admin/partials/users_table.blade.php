{{--
    The account list card — split out so the same markup renders both a
    normal full page load and the fragment AdminController::usersRefresh()
    returns for the live-channel/poll JS on users.blade.php to swap in
    place (see resources/js/app.js's applyLiveRefresh pattern). Lets the
    "Unverified" badge/Resend-verification action disappear live the
    moment someone actually clicks their link, instead of only on a
    manual reload — see AuthController::verifyEmail() firing UserVerified.
--}}
<div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
    <div class="px-6 py-3 border-b border-surface-200 flex items-center justify-between">
        <span class="text-xs text-surface-400">
            @if(!$showInactive && $inactiveCount > 0)
                {{ $inactiveCount }} inactive account{{ $inactiveCount === 1 ? '' : 's' }} hidden
            @endif
        </span>
        @if($showInactive)
            <a href="{{ url()->current() }}" class="text-xs font-medium text-primary-700 hover:underline">Hide inactive accounts</a>
        @elseif($inactiveCount > 0)
            <a href="{{ url()->current() }}?show_inactive=1" class="text-xs font-medium text-primary-700 hover:underline">Show inactive accounts</a>
        @endif
    </div>
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
                        @unless($u->hasVerifiedEmail())
                            <span class="inline-flex items-center gap-1 mt-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-processing-100 text-processing-700">
                                Unverified — hasn't clicked the email link yet
                            </span>
                        @endunless
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
                        @unless($u->hasVerifiedEmail())
                            <form method="POST" action="{{ route('admin.users.resend-verification', $u) }}" class="inline">
                                @csrf
                                <button class="text-xs font-medium text-processing-700 hover:underline">Resend verification</button>
                            </form>
                        @endunless
                        @if($u->is_active)
                            {{-- A centered modal (same pattern as x-document-viewer-modal),
                                 not a popover anchored to this button — an absolutely-
                                 positioned popover still gets clipped by the table's
                                 overflow-x-auto scroll container (position:absolute escapes
                                 normal document FLOW, not an ancestor's overflow clipping),
                                 so it was overflowing/getting cut off regardless. fixed
                                 inset-0 escapes that entirely by anchoring to the viewport,
                                 not this row. Deactivating an approver with pending work
                                 triggers an automatic handoff (see AdminController::
                                 toggleUser()); this optional note just gives the new
                                 approver context for why. --}}
                            <details data-popover class="inline-block align-middle text-left">
                                <summary class="text-xs font-medium text-rejected-600 hover:underline cursor-pointer list-none [&::-webkit-details-marker]:hidden">Deactivate</summary>
                                <div class="fixed inset-0 z-50 bg-surface-900/50 flex items-center justify-center p-4"
                                    onclick="if(event.target === this) this.closest('details').open = false">
                                    <form method="POST" action="{{ route('admin.users.toggle', $u) }}" class="flex flex-col w-full max-w-sm bg-white shadow-elevated border border-surface-200 rounded-xl p-5">
                                        @csrf
                                        <h3 class="text-sm font-semibold text-surface-900 mb-3">Deactivate {{ $u->full_name }}?</h3>
                                        <label class="block text-[11px] font-medium text-surface-600 mb-1">Reason (optional)</label>
                                        <textarea name="reason" rows="3" maxlength="500" placeholder="e.g. Resigned, role change…"
                                            class="block w-full rounded-lg border-surface-300 text-xs px-2 py-1.5 focus:border-primary-500 focus:ring-primary-500 mb-3"></textarea>
                                        <button type="submit" class="block w-full bg-rejected-600 hover:bg-rejected-700 text-white text-xs font-medium py-2 rounded-lg transition-colors">
                                            Confirm Deactivation
                                        </button>
                                    </form>
                                </div>
                            </details>
                        @else
                            <form method="POST" action="{{ route('admin.users.toggle', $u) }}" class="inline">
                                @csrf
                                <button class="text-xs font-medium text-primary-700 hover:underline">Activate</button>
                            </form>
                        @endif
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
