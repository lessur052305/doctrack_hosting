{{--
    Admin's chat panel default view — every active Originator/Approver,
    whether they've chatted with the Admin before or not (Feature: so
    Admin always knows exactly who they'd be talking to). Each row reuses
    <x-user-badge> (Department | Category | Stage, name, role pill) —
    the same identity block the top header shows for the logged-in user,
    now reused for an arbitrary user in a list row.
--}}
<div class="px-4 py-3 border-b border-surface-200 shrink-0">
    <h3 class="text-sm font-semibold text-surface-900">Messages</h3>
</div>
<ul class="flex-1 min-h-0 overflow-y-auto divide-y divide-surface-100">
    @forelse($users as $u)
        @php
            $last = $lastMessageByUser->get($u->user_id);
            $unread = $unreadCounts->get($u->user_id, 0);
        @endphp
        <li>
            <button type="button" onclick="openChatThread({{ $u->user_id }})"
                class="w-full px-4 py-3 hover:bg-surface-50/60 flex items-center gap-3 text-left transition-colors">
                <span class="relative shrink-0">
                    <span class="w-9 h-9 rounded-full bg-primary-100 text-primary-700 flex items-center justify-center text-xs font-semibold">
                        {{ strtoupper(substr($u->full_name, 0, 1)) }}
                    </span>
                    <span class="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full ring-2 ring-white {{ $u->isOnline() ? 'bg-approved-500' : 'bg-surface-300' }}"></span>
                </span>
                <span class="min-w-0 flex-1">
                    <x-user-badge :user="$u" :center="false" />
                    <p class="text-xs text-surface-400 truncate mt-0.5">
                        {{ $last?->body ?: ($last?->attachment_path ? '📷 Photo' : 'No messages yet') }}
                    </p>
                </span>
                @if($unread > 0)
                    <span class="shrink-0 bg-rejected-500 text-white text-[10px] font-semibold rounded-full min-w-[18px] h-[18px] px-1 flex items-center justify-center">{{ $unread }}</span>
                @endif
            </button>
        </li>
    @empty
        <li class="px-4 py-8 text-center text-sm text-surface-400">No users yet.</li>
    @endforelse
</ul>
