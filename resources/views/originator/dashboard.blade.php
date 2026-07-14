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
                    <p class="text-[11px] text-surface-400 mb-1">Approvers' review window is calculated as 25% of the time left until this deadline.</p>
                    <input type="datetime-local" id="due_date" name="due_date" required min="{{ now()->addHour()->format('Y-m-d\TH:i') }}"
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
                <span class="text-xs text-surface-400">{{ $documents->total() }} total</span>
            </div>

            <table class="w-full text-sm">
                <thead class="bg-surface-50 text-surface-500 text-xs uppercase tracking-wide">
                    <tr>
                        <th class="text-left px-6 py-3 font-medium">Document</th>
                        <th class="text-left px-6 py-3 font-medium">Batch</th>
                        <th class="text-left px-6 py-3 font-medium">Category</th>
                        <th class="text-left px-6 py-3 font-medium">Status</th>
                        <th class="text-left px-6 py-3 font-medium">Uploaded</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-surface-100">
                    @forelse($documents as $doc)
                        <tr class="hover:bg-surface-50 transition-colors">
                            <td class="px-6 py-4 font-medium text-surface-800 max-w-xs truncate">{{ $doc->title }}</td>
                            <td class="px-6 py-4 text-surface-500">
                                @if($doc->batch)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-primary-50 text-primary-700 ring-1 ring-inset ring-primary-500/20">
                                        Batch #{{ $doc->batch_id }}
                                    </span>
                                @else
                                    <span class="text-surface-300">—</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-surface-600">{{ $doc->ml_category ?? '—' }}</td>
                            <td class="px-6 py-4"><x-status-badge :status="$doc->global_status" /></td>
                            <td class="px-6 py-4 text-surface-500">{{ $doc->upload_date->diffForHumans() }}</td>
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('originator.documents.show', $doc) }}" class="text-primary-700 hover:text-primary-900 font-medium text-xs">Track &rarr;</a>
                            </td>
                        </tr>
                        @if(!$doc->is_validated && $doc->global_status === 'processing')
                        <tr class="bg-rejected-50/50">
                            <td colspan="6" class="px-6 pb-3 text-xs text-rejected-700">
                                Validation issues: {{ implode(' · ', $doc->validation_errors ?? []) }}
                            </td>
                        </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-10 text-center text-surface-400 text-sm">No documents submitted yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @if($documents->hasPages())
                <div class="px-6 py-4 border-t border-surface-200">{{ $documents->links() }}</div>
            @endif
        </div>
    </div>
</div>
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