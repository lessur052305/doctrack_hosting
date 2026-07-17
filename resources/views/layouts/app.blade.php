<!DOCTYPE html>
<html lang="en" class="h-full bg-surface-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Document Classification & Tracking System')</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full font-sans text-surface-800 antialiased">
<div class="min-h-full flex">

    {{-- ============ SIDEBAR ============ --}}
    {{-- Fixed at every breakpoint so it never affects document flow; below
         `lg` it starts translated off-screen (a true off-canvas drawer) and
         is toggled by the hamburger button in the header, closing the gap
         where the old `hidden lg:flex` version simply vanished with no way
         to reopen it once the viewport (or a resized desktop window) dropped
         under 1024px. --}}
    <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 w-64 flex flex-col bg-primary-900 text-primary-100 -translate-x-full transition-transform duration-300 ease-in-out lg:translate-x-0" aria-hidden="true">
        <div class="flex items-center justify-between gap-2 px-6 h-16 border-b border-primary-800">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-primary-500 flex items-center justify-center font-bold text-white">D</div>
                <span class="font-semibold text-white tracking-tight">DocTrack</span>
            </div>
            <button id="sidebar-close" type="button" class="lg:hidden p-1 rounded-lg text-primary-300 hover:text-white hover:bg-primary-800" aria-label="Close menu">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <nav class="flex-1 overflow-y-auto px-3 py-6 space-y-1 text-sm">
            @auth
                @if(auth()->user()->isAdmin())
                    @include('layouts.nav-admin')
                @elseif(auth()->user()->isOriginator())
                    @include('layouts.nav-originator')
                @elseif(auth()->user()->isApprover())
                    @include('layouts.nav-approver')
                @endif
            @endauth
        </nav>

        @auth
        <div class="px-4 py-4 border-t border-primary-800">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-full bg-primary-600 flex items-center justify-center text-white text-sm font-semibold">
                    {{ strtoupper(substr(auth()->user()->full_name, 0, 1)) }}
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-white truncate">{{ auth()->user()->full_name }}</p>
                    <p class="text-xs text-primary-300 capitalize">{{ auth()->user()->role }}</p>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="text-primary-300 hover:text-white" title="Log out">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                        </svg>
                    </button>
                </form>
            </div>
        </div>
        @endauth
    </aside>

    {{-- Off-canvas backdrop, mobile/tablet only — click anywhere on it to
         close the drawer, same as clicking the X inside it. --}}
    <div id="sidebar-backdrop" class="hidden fixed inset-0 z-30 bg-surface-900/50 lg:hidden"></div>

    {{-- ============ MAIN COLUMN ============ --}}
    {{-- min-w-0: this is a flex item of the row above, so without it, any
         page that renders something wide (e.g. a table needing a min-width
         to stay legible) would refuse to shrink below that content's width
         — stretching this whole column, and with it the page, into
         horizontal scroll instead of letting the wide content scroll
         internally on its own. --}}
    <div class="min-w-0 flex-1 lg:pl-64 flex flex-col min-h-screen">

        {{-- Top bar --}}
        <header class="sticky top-0 z-10 bg-white/80 backdrop-blur border-b border-surface-200 h-16 flex items-center px-4 sm:px-8">
            <button id="sidebar-toggle" type="button" class="lg:hidden -ml-1 mr-3 p-2 rounded-lg text-surface-500 hover:bg-surface-100 hover:text-surface-900" aria-label="Open menu" aria-expanded="false" aria-controls="sidebar">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>
            <h1 class="text-lg font-semibold text-surface-900">@yield('page-title', 'Dashboard')</h1>
            <div class="ml-auto flex items-center gap-4">
                @auth
                    <x-notification-bell />
                    <span class="text-xs px-2.5 py-1 rounded-full bg-surface-100 text-surface-600 font-medium capitalize">{{ auth()->user()->role }}</span>
                @endauth
            </div>
        </header>

        <main class="flex-1 p-4 sm:p-8 space-y-6">
            @if(session('status'))
                <div class="rounded-lg bg-approved-50 border border-approved-500/30 text-approved-700 px-4 py-3 text-sm font-medium transition-opacity duration-300" role="status">
                    {{ session('status') }}
                </div>
            @endif

            @if($errors->any())
                <div class="rounded-lg bg-rejected-50 border border-rejected-500/30 text-rejected-700 px-4 py-3 text-sm" role="alert">
                    <p class="font-medium mb-1">Please correct the following:</p>
                    <ul class="list-disc list-inside space-y-0.5">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</div>
@auth
    <x-document-viewer-modal />
@endauth
<script>
    // Live relative-time updater (Feature: SLA countdowns/expiries update in
    // real time without a page refresh). Any element with a
    // data-live-time="<unix seconds>" attribute gets its text kept in sync
    // every second, formatted as "Xh Ym Zs remaining" (or "... ago" once
    // past) so the seconds digit visibly ticks — a coarser "X minutes from
    // now" style wording only changes once a minute, which looks frozen.
    // Optionally pass data-live-urgent-under="<seconds>" to toggle an
    // "urgent" red color once less than that many seconds remain.
    function __docTrackFormatDuration(totalSeconds) {
        const h = Math.floor(totalSeconds / 3600);
        const m = Math.floor((totalSeconds % 3600) / 60);
        const s = totalSeconds % 60;

        if (h > 0) return `${h}h ${m}m ${s}s`;
        if (m > 0) return `${m}m ${s}s`;
        return `${s}s`;
    }

    function __docTrackRelativeTime(unixSeconds) {
        const diffMs = (unixSeconds * 1000) - Date.now();
        const isFuture = diffMs >= 0;
        const totalSeconds = Math.max(0, Math.round(Math.abs(diffMs) / 1000));
        const duration = __docTrackFormatDuration(totalSeconds);

        return isFuture ? `${duration} remaining` : `${duration} ago`;
    }

    function __docTrackUpdateLiveTimes() {
        document.querySelectorAll('[data-live-time]').forEach((el) => {
            const ts = parseInt(el.getAttribute('data-live-time'), 10);
            if (!ts) return;

            el.textContent = __docTrackRelativeTime(ts);

            const urgentUnder = el.getAttribute('data-live-urgent-under');
            if (urgentUnder !== null) {
                const secondsRemaining = ts - Math.floor(Date.now() / 1000);
                el.classList.toggle('text-rejected-700', secondsRemaining < parseInt(urgentUnder, 10));
                el.classList.toggle('text-surface-600', secondsRemaining >= parseInt(urgentUnder, 10));
            }
        });
    }

    document.addEventListener('DOMContentLoaded', __docTrackUpdateLiveTimes);
    setInterval(__docTrackUpdateLiveTimes, 1000);
</script>
@stack('scripts')
</body>
</html>