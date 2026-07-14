@extends('layouts.app')
@section('title', 'Track Document')
@section('page-title', 'Document Tracking')

@section('content')
<div class="max-w-4xl mx-auto space-y-6">

    <div class="bg-white rounded-xl shadow-card border border-surface-200 p-6">
        <div class="flex items-start justify-between mb-6">
            <div>
                <h2 class="text-base font-semibold text-surface-900">{{ $document->title }}</h2>
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
            <x-status-badge :status="$document->global_status" />
        </div>

        <x-lifecycle-stepper :document="$document" />
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
</div>
@endsection