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
    @else

    <div class="grid grid-cols-1 {{ auth()->user()->isAdmin() ? 'lg:grid-cols-3' : '' }} gap-6">

        <div class="{{ auth()->user()->isAdmin() ? 'lg:col-span-2' : '' }} space-y-6">

            {{-- Search / filter bar --}}
            <div class="bg-white rounded-xl shadow-card border border-surface-200 p-5">
                <form method="GET" class="flex flex-wrap gap-3 items-end">
                    <div class="flex-1 min-w-[140px]">
                        <label class="block text-xs font-medium text-surface-700 mb-1">Keyword</label>
                        <input type="text" id="archive-keyword" name="keyword" value="{{ request('keyword') }}" placeholder="Title or content…" autocomplete="off"
                            class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                    </div>

                    @if($restrictedCategory)
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
                        <input type="date" name="date_from" value="{{ request('date_from') }}" onchange="this.form.submit()"
                            class="rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-surface-700 mb-1">To</label>
                        <input type="date" name="date_to" value="{{ request('date_to') }}" onchange="this.form.submit()"
                            class="rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                    </div>

                    <button class="bg-primary-700 hover:bg-primary-800 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">Search</button>
                    <a href="{{ url()->current() }}"
                        class="inline-flex items-center text-xs font-medium text-surface-700 bg-surface-100 hover:bg-surface-200 border border-surface-300 px-4 py-2 rounded-lg transition-colors">
                        Clear
                    </a>
                </form>
            </div>

            {{-- Results --}}
            <div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-surface-200 flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-surface-900">Approved Documents</h2>
                        @if($isOwnSubmissionsView)
                            <p class="text-xs text-surface-400 mt-0.5">Showing only documents you submitted, across all categories.</p>
                        @endif
                    </div>
                    <span class="text-xs text-surface-400">{{ $documents->total() }} total</span>
                </div>
                <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-sm">
                    <thead class="bg-surface-50 text-surface-500 text-xs uppercase tracking-wide">
                        <tr>
                            <th class="text-left px-6 py-3 font-medium">Document</th>
                            <th class="text-left px-6 py-3 font-medium">Category</th>
                            <th class="text-left px-6 py-3 font-medium">Originator</th>
                            <th class="text-left px-6 py-3 font-medium">Approved</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody id="archive-rows" class="divide-y divide-surface-100">
                        @forelse($documents as $doc)
                            <tr class="archive-row hover:bg-surface-50 transition-colors" data-document-title="{{ strtolower($doc->title) }}" data-document-category="{{ strtolower($doc->ml_category) }}">
                                <td class="px-6 py-3 font-medium text-surface-800 max-w-xs truncate">
                                    {{ $doc->title }}
                                    @if($doc->is_legacy_import)
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-processing-50 text-processing-700 align-middle">Imported</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3 text-surface-600">{{ $doc->ml_category }}</td>
                                <td class="px-6 py-3 text-surface-500">{{ $doc->originator->full_name ?? '—' }}</td>
                                <td class="px-6 py-3 text-surface-500">{{ $doc->updated_at->format('M j, Y') }}</td>
                                <td class="px-6 py-3 text-right">
                                    <a href="{{ route('archive.download', $doc) }}" class="text-primary-700 hover:text-primary-900 font-medium text-xs">Download &darr;</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-10 text-center text-surface-400 text-sm">No archived documents match your search.</td>
                            </tr>
                        @endforelse
                        <tr id="archive-no-matches" class="hidden">
                            <td colspan="5" class="px-6 py-10 text-center text-surface-400 text-sm">No documents on this page match "<span id="archive-no-matches-term"></span>". Press Enter to search every page.</td>
                        </tr>
                    </tbody>
                </table>
                </div>
                @if($documents->hasPages())
                    <div class="px-6 py-4 border-t border-surface-200">{{ $documents->links() }}</div>
                @endif
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
                                <option value="{{ $c }}">{{ $c }}</option>
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
    // Real-time, client-side filter over the rows already rendered on this
    // page — instant, no round trip. Keyword and Category combine (both
    // must match). Pressing Enter still submits the surrounding <form>
    // normally, running both filters server-side (see
    // ArchiveController::index()) across every page, not just this one.
    (function () {
        const keywordInput = document.getElementById('archive-keyword');
        const categorySelect = document.getElementById('archive-category');
        const rows = Array.from(document.querySelectorAll('.archive-row'));
        const noMatches = document.getElementById('archive-no-matches');
        const noMatchesTerm = document.getElementById('archive-no-matches-term');
        if (rows.length === 0) return;

        const applyFilters = () => {
            const term = keywordInput ? keywordInput.value.trim().toLowerCase() : '';
            const category = categorySelect ? categorySelect.value.toLowerCase() : '';
            let visibleCount = 0;

            rows.forEach((row) => {
                const matchesKeyword = term === '' || row.dataset.documentTitle.includes(term);
                const matchesCategory = category === '' || row.dataset.documentCategory === category;
                const matches = matchesKeyword && matchesCategory;
                row.classList.toggle('hidden', !matches);
                if (matches) visibleCount++;
            });

            const showNoMatches = (term !== '' || category !== '') && visibleCount === 0;
            if (noMatches) {
                noMatches.classList.toggle('hidden', !showNoMatches);
                if (showNoMatches && noMatchesTerm) {
                    noMatchesTerm.textContent = term || (categorySelect ? categorySelect.options[categorySelect.selectedIndex].text : '');
                }
            }
        };

        if (keywordInput) keywordInput.addEventListener('input', applyFilters);
        if (categorySelect) categorySelect.addEventListener('change', applyFilters);
    })();
</script>
@endsection