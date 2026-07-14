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
    <aside class="hidden lg:flex lg:flex-col lg:w-64 lg:fixed lg:inset-y-0 bg-primary-900 text-primary-100">
        <div class="flex items-center gap-2 px-6 h-16 border-b border-primary-800">
            <div class="w-8 h-8 rounded-lg bg-primary-500 flex items-center justify-center font-bold text-white">D</div>
            <span class="font-semibold text-white tracking-tight">DocTrack</span>
        </div>

        <nav class="flex-1 px-3 py-6 space-y-1 text-sm">
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

    {{-- ============ MAIN COLUMN ============ --}}
    <div class="flex-1 lg:pl-64 flex flex-col min-h-screen">

        {{-- Top bar --}}
        <header class="sticky top-0 z-10 bg-white/80 backdrop-blur border-b border-surface-200 h-16 flex items-center px-4 sm:px-8">
            <h1 class="text-lg font-semibold text-surface-900">@yield('page-title', 'Dashboard')</h1>
            <div class="ml-auto flex items-center gap-4">
                @auth
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
@stack('scripts')
</body>
</html>