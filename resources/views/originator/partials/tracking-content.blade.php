{{--
    The whole tracking page body — split out from tracking.blade.php so the
    same markup can be rendered two ways: a normal full page load, and a
    fragment returned by DocumentController::trackingRefresh() for the
    live-poll JS to swap in place (see tracking.blade.php) without a full
    page reload.
--}}
<div class="bg-white rounded-xl shadow-card border border-surface-200 p-6">
    <div class="flex items-start justify-between mb-6">
        <div>
            <div class="flex items-center gap-2">
                <h2 class="text-base font-semibold text-surface-900">{{ $document->title }}</h2>
                @if($document->version_number > 1)
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-indigo-50 text-indigo-700">v{{ $document->version_number }}</span>
                @endif
                @if($document->is_legacy_import)
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-processing-50 text-processing-700">Imported</span>
                @endif
            </div>
            @if($document->is_legacy_import)
                <p class="text-xs text-processing-700 mt-1">
                    Imported directly by an administrator — not classified, validated, or peer-reviewed through the normal approval workflow.
                </p>
            @endif
            @if($document->previousVersion)
                <p class="text-xs text-surface-500 mt-1">
                    Resubmission of
                    <a href="{{ route('originator.documents.show', $document->previousVersion) }}" class="text-primary-700 hover:underline font-medium">
                        "{{ $document->previousVersion->title }}" (v{{ $document->previousVersion->version_number }})
                    </a>, which was rejected.
                </p>
            @endif
            @if($document->nextVersion)
                <p class="text-xs text-rejected-700 mt-1">
                    Superseded by
                    <a href="{{ route('originator.documents.show', $document->nextVersion) }}" class="hover:underline font-medium">
                        a resubmitted version (v{{ $document->nextVersion->version_number }})
                    </a> — that one reflects the current state of this request.
                </p>
            @endif
            <p class="text-xs text-surface-500 mt-1">
                Category: <span class="font-medium text-surface-700">{{ $document->ml_category ?? 'Unclassified' }}</span>
                @if($document->ml_confidence)
                    &middot; Confidence: {{ $document->ml_confidence }}%
                @endif
                @if($document->used_ocr_fallback)
                    &middot; <span class="text-processing-700">OCR fallback used</span>
                @endif
                &middot;
                <button type="button"
                    onclick="openDocumentViewer('{{ route('documents.file', $document) }}', '{{ $document->mime_type }}', '{{ addslashes($document->original_filename ?? $document->title) }}')"
                    class="text-primary-700 hover:underline font-medium">View original file</button>
            </p>
        </div>
        <x-status-badge :status="$document->display_status" />
    </div>

    <x-lifecycle-stepper :document="$document" />

    @if($document->global_status === 'rejected' && !$document->nextVersion)
        <div class="mt-6 pt-6 border-t border-surface-200">
            <details class="text-sm">
                <summary class="cursor-pointer font-medium text-primary-700 hover:underline">Resubmit a revised version</summary>
                <form method="POST" action="{{ route('originator.documents.resubmit', $document) }}" enctype="multipart/form-data" class="mt-3 space-y-3 max-w-sm">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium text-surface-700 mb-1">Revised document</label>
                        <input type="file" name="file" required class="w-full text-xs">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-surface-700 mb-1">Due date &amp; time</label>
                        <input type="datetime-local" name="due_date" required min="{{ now()->addMinutes(15)->format('Y-m-d\TH:i') }}"
                            class="w-full rounded-lg border-surface-300 text-sm px-3 py-2">
                    </div>
                    <button class="w-full bg-primary-700 hover:bg-primary-800 text-white text-xs font-medium py-2 rounded-lg">Resubmit</button>
                </form>
            </details>
        </div>
    @endif
</div>

<div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-surface-200">
        <h3 class="text-sm font-semibold text-surface-900">Approval Stages</h3>
    </div>
    <div class="p-6">
        <x-workflow-stage-list :document="$document" />
    </div>
</div>

<div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-surface-200">
        <h3 class="text-sm font-semibold text-surface-900">Audit Trail</h3>
    </div>
    <ul class="divide-y divide-surface-100">
        @foreach($document->auditLogs as $log)
            <li class="px-6 py-3 text-sm flex items-start gap-3">
                <span class="mt-1 w-1.5 h-1.5 rounded-full bg-primary-500 flex-shrink-0"></span>
                <div>
                    <p class="text-surface-700">{{ $log->description }}</p>
                    <p class="text-xs text-surface-400 mt-0.5">{{ $log->timestamp->format('M j, Y g:i A') }} @if($log->user) &middot; {{ $log->user->full_name }} @endif</p>
                </div>
            </li>
        @endforeach
    </ul>
</div>
