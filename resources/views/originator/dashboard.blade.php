@extends('layouts.app')
@section('title', 'Upload & Track')
@section('page-title', 'Upload & Track Documents')

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    {{-- Drag-and-drop ingestion card --}}
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-6">
            <h2 class="text-sm font-semibold text-surface-900 mb-1">New Submission</h2>
            <p class="text-xs text-surface-500 mb-4">The system will classify, validate, and route your document(s) automatically. Select more than one file to submit them together as a single grouped approval request.</p>

            <form method="POST" action="{{ route('originator.documents.store') }}" enctype="multipart/form-data" id="upload-form">
                @csrf
                <label for="file-input" id="dropzone"
                    class="flex flex-col items-center justify-center gap-2 border-2 border-dashed border-surface-300 rounded-xl py-10 px-4 text-center cursor-pointer transition-colors hover:border-primary-400 hover:bg-primary-50/50">
                    <svg class="w-9 h-9 text-primary-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                    </svg>
                    <span class="text-sm font-medium text-surface-700">Drag & drop your document(s) here</span>
                    <span class="text-xs text-surface-400">or click to browse — PDF, DOCX, TXT, PNG, JPG (max 20MB each, up to 20 files)</span>
                    <span id="file-name" class="text-xs font-medium text-primary-700 mt-1"></span>
                    <input id="file-input" type="file" name="files[]" class="sr-only" multiple required>
                </label>

                <div class="mt-4">
                    <label for="due_date" class="block text-xs font-medium text-surface-700 mb-1">Due date &amp; time <span class="text-rejected-700">*</span></label>
                    <p class="text-[11px] text-surface-400 mb-1">Approvers' review window is 25% of the time left until this deadline (a flat 15 minutes if due within the next hour).</p>
                    <input type="datetime-local" id="due_date" name="due_date" required min="{{ now()->addMinutes(15)->format('Y-m-d\TH:i') }}"
                        class="w-full rounded-lg border-surface-300 focus:border-primary-500 focus:ring-primary-500 text-sm px-3 py-2">
                </div>

                <button type="submit"
                    class="mt-4 w-full bg-primary-700 hover:bg-primary-800 text-white text-sm font-medium py-2.5 rounded-lg transition-colors">
                    Submit Document(s)
                </button>
            </form>
        </div>
    </div>

    {{-- Live tracking list --}}
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-surface-200 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-surface-900">Your Submissions</h2>
                <span id="submissions-total" class="text-xs text-surface-400">{{ $documents->total() }} total</span>
            </div>

            <form method="GET" class="px-6 py-4 border-b border-surface-200 space-y-3">
                <div class="relative">
                    <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-surface-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
                    </svg>
                    <input type="text" id="document-search" name="document" value="{{ request('document') }}"
                        placeholder="Search document" autocomplete="off"
                        class="w-full rounded-lg border-surface-300 text-sm pl-9 pr-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <select name="status" onchange="this.form.submit()" class="rounded-lg border-surface-300 text-xs px-3 py-2">
                        <option value="">All Statuses</option>
                        @foreach(['processing' => 'Processing', 'classified_validated' => 'Awaiting Approval', 'approved' => 'Approved', 'auto_approved' => 'Auto-Approved', 'rejected' => 'Rejected'] as $value => $label)
                            <option value="{{ $value }}" {{ request('status') === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    <select name="category" onchange="this.form.submit()" class="rounded-lg border-surface-300 text-xs px-3 py-2">
                        <option value="">All Categories</option>
                        @foreach($categories as $c)
                            <option value="{{ $c }}" {{ request('category') === $c ? 'selected' : '' }}>{{ $c }}</option>
                        @endforeach
                    </select>
                    <button class="text-xs font-medium bg-primary-700 hover:bg-primary-800 text-white px-4 py-2 rounded-lg">Filter</button>
                    @if(request('document') || request('status') || request('category'))
                        <a href="{{ route('originator.dashboard') }}" class="text-xs font-medium text-surface-500 hover:underline">Clear</a>
                    @endif
                </div>
            </form>

            <div id="submissions-list" data-user-id="{{ auth()->id() }}" data-poll-url="{{ route('originator.documents.poll') }}" data-refresh-url="{{ route('originator.documents.refresh') }}">
                @include('originator.partials.submissions')
            </div>
        </div>
    </div>
</div>

<script>
    // Real-time, client-side filter over the rows already rendered on this
    // page — instant, no round trip. Pressing Enter still submits the
    // surrounding <form> normally, running the "document" filter
    // server-side (see DocumentController::dashboard()) across every page.
    // A plain function (not an IIFE) so it can be re-run after every live
    // swap below — otherwise a swap would silently undo an active search.
    function applySubmissionFilter() {
        const input = document.getElementById('document-search');
        const rows = Array.from(document.querySelectorAll('.submission-row'));
        const noMatches = document.getElementById('submission-no-matches');
        const noMatchesTerm = document.getElementById('submission-no-matches-term');
        if (!input || rows.length === 0 || !noMatches) return;

        const term = input.value.trim().toLowerCase();
        let visibleCount = 0;

        rows.forEach((row) => {
            const matches = term === '' || row.dataset.documentTitle.includes(term);
            row.classList.toggle('hidden', !matches);
            if (matches) visibleCount++;
        });

        const showNoMatches = term !== '' && visibleCount === 0;
        noMatches.classList.toggle('hidden', !showNoMatches);
        if (showNoMatches) noMatchesTerm.textContent = input.value.trim();
    }

    document.getElementById('document-search')?.addEventListener('input', applySubmissionFilter);

    // Live-updates the submissions table without a full page reload —
    // instant via Reverb (see startLiveChannel in resources/js/app.js)
    // the moment one of this originator's documents changes status
    // (processing -> approved, say) or a new one is routed; the slow poll
    // behind it is only a fallback in case the WebSocket connection is down.
    //
    // Wrapped in DOMContentLoaded, not a bare IIFE — see the matching
    // comment in approver/dashboard.blade.php for why: this plain inline
    // script would otherwise run before app.js's deferred module script
    // has defined startLiveChannel/startLivePoll, throw immediately, and
    // silently never wire anything up.
    document.addEventListener('DOMContentLoaded', function () {
        const listEl = document.getElementById('submissions-list');
        if (!listEl) return;

        const opts = {
            refreshUrl: listEl.dataset.refreshUrl,
            target: listEl,
            preserveQueryString: true,
            onSwap: () => {
                const total = listEl.querySelector('[data-total-count]')?.dataset.totalCount;
                if (total !== undefined) {
                    document.getElementById('submissions-total').textContent = total + ' total';
                }
                applySubmissionFilter();
            },
        };

        startLiveChannel(`originator.${listEl.dataset.userId}`, '.document.status-changed', opts);
        startLivePoll({ ...opts, pollUrl: listEl.dataset.pollUrl });
    });
</script>
@endsection

@push('scripts')
<script>
    const input = document.getElementById('file-input');
    const dropzone = document.getElementById('dropzone');
    const fileName = document.getElementById('file-name');

    function describeFiles(fileList) {
        if (!fileList.length) return '';
        if (fileList.length === 1) return fileList[0].name;
        return fileList.length + ' files selected: ' + Array.from(fileList).map(f => f.name).join(', ');
    }

    input.addEventListener('change', () => {
        fileName.textContent = describeFiles(input.files);
    });

    ['dragover', 'dragenter'].forEach(evt =>
        dropzone.addEventListener(evt, e => { e.preventDefault(); dropzone.classList.add('border-primary-500', 'bg-primary-50'); })
    );
    ['dragleave', 'drop'].forEach(evt =>
        dropzone.addEventListener(evt, e => { e.preventDefault(); dropzone.classList.remove('border-primary-500', 'bg-primary-50'); })
    );
    dropzone.addEventListener('drop', e => {
        if (e.dataTransfer.files.length) {
            input.files = e.dataTransfer.files;
            fileName.textContent = describeFiles(input.files);
        }
    });
</script>
@endpush