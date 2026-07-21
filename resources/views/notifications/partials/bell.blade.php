{{--
    The notification bell's inner content (summary trigger + dropdown) —
    split out from components/notification-bell.blade.php so the same
    markup can be rendered two ways: a normal full page load, and a
    fragment returned by NotificationController::refresh() for the
    live-poll JS to swap in place (see resources/js/app.js) without a full
    page reload. Deliberately swaps only the <details>'s children, never
    the <details> tag itself, so its open/closed state (whether the
    dropdown is currently expanded) survives the swap untouched.
--}}
<summary class="list-none cursor-pointer relative inline-flex items-center justify-center w-9 h-9 rounded-full hover:bg-surface-100 transition-colors">
    <svg class="w-5 h-5 text-surface-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
    </svg>
    @if($unreadCount > 0)
        <span class="absolute -top-0.5 -right-0.5 bg-rejected-500 text-white text-[10px] font-semibold rounded-full w-4 h-4 flex items-center justify-center ring-2 ring-white">{{ $unreadCount > 9 ? '9+' : $unreadCount }}</span>
    @endif
</summary>
<div class="absolute right-0 mt-2 w-[30rem] max-w-[94vw] bg-white rounded-xl shadow-elevated border border-surface-200/80 z-30">
    <div class="px-5 py-4 border-b border-surface-200 flex items-center justify-between">
        <h3 class="text-sm font-semibold text-surface-900 tracking-tight">Notifications</h3>
        @if($unreadCount > 0)
            <form method="POST" action="{{ route('notifications.readAll') }}">
                @csrf
                <button class="text-xs text-primary-700 hover:underline font-medium">Mark all read</button>
            </form>
        @endif
    </div>
    <ul class="divide-y divide-surface-100 max-h-[28rem] overflow-y-auto">
        @forelse($unread as $n)
            <li class="flex items-start gap-3 px-5 py-4 hover:bg-surface-50 transition-colors {{ $n->is_read ? '' : 'bg-primary-50/40' }}">
                @unless($n->is_read)
                    <span class="mt-1.5 w-2 h-2 rounded-full bg-primary-500 flex-shrink-0 shadow-[0_0_0_3px] shadow-primary-500/15"></span>
                @else
                    <span class="mt-1.5 w-2 h-2 flex-shrink-0"></span>
                @endunless
                <div class="min-w-0 flex-1">
                    @if($n->priority === 'high')
                        <span class="inline-flex items-center gap-1 mb-1 text-[10px] font-semibold text-rejected-700 uppercase tracking-wide">
                            <span class="w-1.5 h-1.5 rounded-full bg-rejected-500"></span> High priority
                        </span>
                    @endif
                    <p class="text-sm text-surface-700 leading-relaxed break-words">{{ $n->message_body }}</p>
                    <p class="text-xs text-surface-400 mt-1.5">{{ $n->created_at->diffForHumans() }}</p>
                </div>
            </li>
        @empty
            <li class="px-5 py-8 text-center text-sm text-surface-400">You're all caught up.</li>
        @endforelse
    </ul>
    <div class="px-5 py-3 border-t border-surface-200 text-center">
        <a href="{{ route('notifications.index') }}" class="text-xs font-medium text-primary-700 hover:underline">View all notifications</a>
    </div>
</div>
