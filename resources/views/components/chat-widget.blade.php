{{--
    Floating chat icon (Feature: message the single Admin from anywhere).
    A persistent <details> like the notification bell, fixed to the
    viewport instead of inline in the header. Panel content is always
    AJAX-fetched on open (see app.js's openChat* functions) — Admin
    defaults to the full user roster, everyone else straight into their
    one thread with the Admin (there's exactly one).
--}}
@php
    $admin = auth()->user()->isAdmin() ? null : \App\Models\User::adminAccount();
@endphp
{{-- A non-admin with no Admin account configured has nobody to message —
     hide the widget entirely rather than render a launcher that 404s the
     moment it's opened. Never actually empty in production (exactly one
     Admin always exists), but real for an incomplete fixture/setup. --}}
@if(auth()->user()->isAdmin() || $admin)
    <details class="fixed bottom-5 right-5 z-30" id="chat-widget" data-popover
        data-poll-url="{{ route('chat.poll') }}"
        data-refresh-url="{{ route('chat.refresh') }}"
        data-send-url="{{ route('chat.send') }}"
        data-user-id="{{ auth()->id() }}"
        data-is-admin="{{ auth()->user()->isAdmin() ? '1' : '0' }}"
        @if($admin)
            data-admin-id="{{ $admin->user_id }}"
        @endif
    >
        <summary class="list-none cursor-pointer relative inline-flex items-center justify-center w-14 h-14 rounded-full bg-primary-700 hover:bg-primary-800 shadow-elevated text-white transition-colors">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-6l-4 4v-4z"/>
            </svg>
            <span id="chat-unread-badge" class="hidden absolute -top-1 -right-1 bg-rejected-500 text-white text-[10px] font-semibold rounded-full min-w-[18px] h-[18px] px-1 flex items-center justify-center ring-2 ring-white"></span>
        </summary>
        <div class="fixed inset-x-4 bottom-24 sm:absolute sm:inset-x-auto sm:bottom-16 sm:right-0 w-auto sm:w-96 h-[32rem] max-h-[70vh] bg-white rounded-xl shadow-elevated border border-surface-200/80 flex flex-col overflow-hidden">
            <div id="chat-panel-body" class="flex-1 min-h-0 flex flex-col overflow-hidden">
                <p class="text-center text-sm text-surface-400 py-8">Loading…</p>
            </div>
        </div>
    </details>
@endif
