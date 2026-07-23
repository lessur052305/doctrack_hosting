@extends('layouts.app')
@section('title', 'Archive')
@section('page-title', 'Document Archive & Repository')

@section('content')
<div class="space-y-6">

    @if($noCategoryAssigned)
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-12 text-center">
            <p class="text-sm text-surface-600 font-medium">No document category has been assigned to your account yet.</p>
            <p class="text-xs text-surface-400 mt-1">Ask an Admin to assign you a category from User Accounts to unlock the archive.</p>
        </div>

    @elseif($showFolders)
        {{-- Folders only — no search bar, no Import Legacy panel here.
             Both only make sense once you're inside a specific category
             (see the else branch below); showing them here duplicated the
             same controls twice and added noise to what should be a plain
             "pick a category" screen. --}}
        <h2 class="text-sm font-semibold text-surface-900 mb-3">
            Browse by Category
            @if($isOwnSubmissionsView)
                <span class="text-xs font-normal text-surface-400">— your own approved submissions</span>
            @endif
        </h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-6">
            @foreach($folders as $folder)
                <a href="{{ url()->current() }}?category={{ urlencode($folder->category) }}" class="group block">
                    {{-- Two rounded pieces (tab + body), not a clip-path
                         polygon — clip-path only does straight-line corners,
                         which read as "pointy" rather than a real folder.
                         Gradients on both pieces give it depth instead of a
                         flat fill. Same blue gradient as the sidebar's "D"
                         logo badge (layouts/app.blade.php) — from-primary-400
                         to-primary-600 — for brand consistency. --}}
                    <div class="w-24 h-6 ml-5 rounded-t-lg bg-gradient-to-br from-primary-300 to-primary-500 group-hover:from-primary-400 group-hover:to-primary-600 transition-colors"></div>
                    <div class="-mt-px h-32 rounded-b-xl rounded-tr-xl bg-gradient-to-br from-primary-400 to-primary-600 group-hover:from-primary-500 group-hover:to-primary-700 shadow-lg group-hover:shadow-xl group-hover:-translate-y-0.5 transition-all flex flex-col items-center justify-center text-center px-4">
                        <h3 class="text-sm font-semibold text-white drop-shadow-sm">{{ $folder->category }}</h3>
                        <p class="text-xs text-primary-100 mt-0.5">{{ $folder->total }} document{{ $folder->total === 1 ? '' : 's' }}</p>
                        @if($folder->disputed > 0 || $folder->auto_approved > 0)
                            <div class="flex flex-wrap justify-center gap-1.5 mt-2">
                                @if($folder->disputed > 0)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-white text-processing-700">{{ $folder->disputed }} disputed</span>
                                @endif
                                @if($folder->auto_approved > 0)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-white text-approved-700">{{ $folder->auto_approved }} auto-approved</span>
                                @endif
                            </div>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>

    @else

    <div class="grid grid-cols-1 {{ auth()->user()->isAdmin() ? 'lg:grid-cols-3' : '' }} gap-6">

        <div class="{{ auth()->user()->isAdmin() ? 'lg:col-span-2' : '' }} space-y-6">

            {{-- Search / filter bar — inputs are live (see script below):
                 typing/changing any of these fetches fresh results from the
                 server and swaps them in without a page reload. The <form>
                 and Search/Clear buttons remain a working no-JS fallback. --}}
            <div class="bg-white rounded-xl shadow-card border border-surface-200 p-5">
                @unless(auth()->user()->isApprover())
                    <a href="{{ url()->current() }}" class="inline-flex items-center gap-1 text-xs font-medium text-primary-700 hover:underline mb-3">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                        All Categories
                    </a>
                @endunless
                <form method="GET" id="archive-filter-form" class="flex flex-wrap gap-3 items-end">
                    <div class="flex-1 min-w-[140px]">
                        <label class="block text-xs font-medium text-surface-700 mb-1">Keyword</label>
                        <input type="text" id="archive-keyword" name="keyword" value="{{ request('keyword') }}" placeholder="Title or content…" autocomplete="off"
                            class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                    </div>

                    @if($restrictedCategory)
                        <input type="hidden" name="category" value="{{ $restrictedCategory }}">
                        <div>
                            <label class="block text-xs font-medium text-surface-700 mb-1">Category</label>
                            <span class="inline-flex items-center px-3 py-2 rounded-lg bg-surface-100 text-sm font-medium text-surface-700">{{ $restrictedCategory }}</span>
                        </div>
                    @else
                        <div>
                            <label class="block text-xs font-medium text-surface-700 mb-1">Category</label>
                            <select name="category" id="archive-category" class="rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                                <option value="">All Categories</option>
                                @foreach($categories as $c)
                                    <option value="{{ $c }}" @selected(request('category') === $c)>{{ $c }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div>
                        <label class="block text-xs font-medium text-surface-700 mb-1">From</label>
                        <input type="date" name="date_from" value="{{ request('date_from') }}"
                            class="rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-surface-700 mb-1">To</label>
                        <input type="date" name="date_to" value="{{ request('date_to') }}"
                            class="rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-surface-700 mb-1">Sort</label>
                        <select name="sort" id="archive-sort" class="rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                            <option value="newest" @selected(request('sort', 'newest') === 'newest')>Newest first</option>
                            <option value="oldest" @selected(request('sort') === 'oldest')>Oldest first</option>
                            <option value="originator" @selected(request('sort') === 'originator')>Originator (A–Z)</option>
                        </select>
                    </div>

                    <button class="bg-primary-700 hover:bg-primary-800 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">Search</button>
                    <a href="{{ url()->current() }}{{ $restrictedCategory ? '?category=' . urlencode($restrictedCategory) : '' }}"
                        class="inline-flex items-center text-xs font-medium text-surface-700 bg-surface-100 hover:bg-surface-200 border border-surface-300 px-4 py-2 rounded-lg transition-colors">
                        Clear
                    </a>
                </form>
            </div>

            <div id="archive-results" data-refresh-url="{{ route('archive.refresh') }}">
                @include('archive.partials.results')
            </div>
        </div>

        @if(auth()->user()->isAdmin())
        <div>
            <div class="bg-white rounded-xl shadow-card border border-surface-200 p-6">
                <h2 class="text-sm font-semibold text-surface-900 mb-1">Import Legacy Document</h2>
                <p class="text-xs text-surface-500 mb-4">Directly archive a pre-existing, already-approved document — bypasses classification, validation, and the approval workflow.</p>

                <form method="POST" action="{{ route('admin.archive.legacy') }}" enctype="multipart/form-data" class="space-y-3">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium text-surface-700 mb-1">File</label>
                        <input type="file" name="file" required accept=".pdf,.docx,.doc,.txt,.png,.jpg,.jpeg"
                            class="w-full text-xs text-surface-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-surface-700 mb-1">Category</label>
                        <select name="category" required class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                            @foreach(\App\Services\ValidationService::knownCategories() as $c)
                                <option value="{{ $c }}" @selected(request('category') === $c)>{{ $c }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-surface-700 mb-1">Title (optional)</label>
                        <input type="text" name="title" placeholder="Defaults to the file name"
                            class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-surface-700 mb-1">Reason for direct import <span class="text-rejected-700">*</span></label>
                        <p class="text-[11px] text-surface-400 mb-1">Recorded in the audit trail — this is the only record of why this document skipped review.</p>
                        <textarea name="import_reason" required minlength="10" maxlength="500" rows="2" placeholder="e.g. Digitizing 2019 paper records approved before this system existed"
                            class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500"></textarea>
                    </div>
                    <button class="w-full bg-primary-700 hover:bg-primary-800 text-white text-sm font-medium py-2.5 rounded-lg transition-colors">
                        Add to Archive
                    </button>
                </form>
            </div>
        </div>
        @endif
    </div>
    @endif
</div>

<script>
    // Live search (Feature: instant results as you type, no page reload) —
    // debounced fetch to ArchiveController::refresh(), which returns just
    // the results-table fragment (archive/partials/results.blade.php) to
    // swap into #archive-results. Keyword is debounced since it fires on
    // every keystroke; category/date/sort fire immediately since they're
    // discrete choices, not continuous typing. The surrounding <form> and
    // Search/Clear buttons keep working as a plain full-page-reload
    // fallback if JS is unavailable — nothing here is required for the
    // page to function.
    (function () {
        const resultsEl = document.getElementById('archive-results');
        if (!resultsEl) return;

        const form = document.getElementById('archive-filter-form');
        const keywordInput = document.getElementById('archive-keyword');
        const refreshUrl = resultsEl.dataset.refreshUrl;
        let debounceTimer = null;
        let currentRequest = null;

        const runSearch = () => {
            const params = new URLSearchParams(new FormData(form));
            // Drop empty params instead of sending "keyword=" etc. — keeps
            // the pushed URL clean and matches how a normal GET submit behaves.
            Array.from(params.keys()).forEach((key) => {
                if (params.get(key) === '') params.delete(key);
            });

            if (currentRequest) currentRequest.abort();
            currentRequest = new AbortController();

            fetch(`${refreshUrl}?${params.toString()}`, {
                headers: { Accept: 'text/html' },
                signal: currentRequest.signal,
            })
                .then((res) => (res.ok ? res.text() : Promise.reject(res)))
                .then((html) => {
                    resultsEl.innerHTML = html;
                    const query = params.toString();
                    history.replaceState(null, '', query ? `${window.location.pathname}?${query}` : window.location.pathname);
                })
                .catch(() => {}); // aborted/failed — leave the last good results showing
        };

        if (keywordInput) {
            keywordInput.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(runSearch, 300);
            });
        }

        form.querySelectorAll('select[name="category"], input[name="date_from"], input[name="date_to"], select[name="sort"]')
            .forEach((el) => el.addEventListener('change', runSearch));

        // Pagination links inside the swapped-in fragment point at the full
        // page (so paging still works if JS never loaded) — intercept them
        // and fetch the SAME query string from the refresh endpoint instead,
        // so paging stays as live as searching rather than swapping in a
        // full HTML document into this fragment container.
        resultsEl.addEventListener('click', (e) => {
            const link = e.target.closest('a[href]');
            if (!link || !resultsEl.contains(link)) return;
            const url = new URL(link.href, window.location.origin);
            if (url.pathname !== window.location.pathname) return; // e.g. a Download link — let it navigate normally
            e.preventDefault();
            fetch(`${refreshUrl}?${url.searchParams.toString()}`, { headers: { Accept: 'text/html' } })
                .then((res) => (res.ok ? res.text() : Promise.reject(res)))
                .then((html) => {
                    resultsEl.innerHTML = html;
                    history.replaceState(null, '', link.href);
                })
                .catch(() => {});
        });
    })();
</script>
@endsection
