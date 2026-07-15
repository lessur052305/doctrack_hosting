@extends('layouts.app')
@section('title', 'Notifications')
@section('page-title', 'Notifications')

@section('content')
<div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-surface-200 flex items-center justify-between">
        <h2 class="text-sm font-semibold text-surface-900">All Notifications</h2>
        <form method="POST" action="{{ route('notifications.readAll') }}">
            @csrf
            <button class="text-xs font-medium text-primary-700 hover:underline">Mark all read</button>
        </form>
    </div>
    <ul class="divide-y divide-surface-100">
        @forelse($notifications as $n)
            <li class="px-6 py-4 flex items-start justify-between gap-4 {{ $n->is_read ? '' : 'bg-primary-50/40' }}">
                <div class="min-w-0">
                    <p class="text-sm text-surface-800 {{ $n->priority === 'high' ? 'font-semibold text-rejected-700' : '' }}">{{ $n->message_body }}</p>
                    <p class="text-xs text-surface-400 mt-1">{{ $n->created_at->format('M j, Y g:i A') }}</p>
                </div>
                @unless($n->is_read)
                    <form method="POST" action="{{ route('notifications.read', $n) }}">
                        @csrf
                        <button class="text-xs font-medium text-primary-700 hover:underline whitespace-nowrap">Mark read</button>
                    </form>
                @endunless
            </li>
        @empty
            <li class="px-6 py-10 text-center text-sm text-surface-400">No notifications yet.</li>
        @endforelse
    </ul>
    <div class="px-6 py-4 border-t border-surface-200">{{ $notifications->links() }}</div>
</div>
@endsection
