@php
    $unread = auth()->user()->notifications()->where('is_read', false)->limit(6)->get();
    $unreadCount = auth()->user()->notifications()->where('is_read', false)->count();
@endphp
<details class="relative" id="notification-bell" data-popover data-user-id="{{ auth()->id() }}"
    data-poll-url="{{ route('notifications.poll') }}" data-refresh-url="{{ route('notifications.refresh') }}">
    @include('notifications.partials.bell')
</details>
