@php
    $link = fn($route) => 'flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-150 ' .
        (request()->routeIs($route.'*')
            ? 'bg-white/10 text-white shadow-inner ring-1 ring-white/10'
            : 'text-primary-200/85 hover:bg-white/5 hover:text-white');
@endphp

<a href="{{ route('originator.dashboard') }}" class="{{ $link('originator.dashboard') }}">
    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
    Upload &amp; Track
</a>
<a href="{{ route('originator.archive') }}" class="{{ $link('originator.archive') }}">
    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 01-2-2V5a1 1 0 011-1h16a1 1 0 011 1v1a2 2 0 01-2 2M5 8v10a1 1 0 001 1h12a1 1 0 001-1V8m-9 4h4"/></svg>
    Archive
</a>
