@php
    $link = fn($route) => 'flex items-center gap-3 px-3 py-2 rounded-lg transition-colors ' .
        (request()->routeIs($route.'*') ? 'bg-primary-800 text-white' : 'text-primary-200 hover:bg-primary-800/60 hover:text-white');
@endphp

<a href="{{ route('approver.dashboard') }}" class="{{ $link('approver.dashboard') }}">
    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    Review Queue
</a>
<a href="{{ route('approver.archive') }}" class="{{ $link('approver.archive') }}">
    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 01-2-2V5a1 1 0 011-1h16a1 1 0 011 1v1a2 2 0 01-2 2M5 8v10a1 1 0 001 1h12a1 1 0 001-1V8m-9 4h4"/></svg>
    Archive
</a>
