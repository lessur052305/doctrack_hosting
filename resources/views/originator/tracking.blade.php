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
        <table class="w-full text-sm">
            <thead class="bg-surface-50 text-surface-500 text-xs uppercase tracking-wide">
                <tr>
                    <th class="text-left px-6 py-3 font-medium">Stage</th>
                    <th class="text-left px-6 py-3 font-medium">Approver</th>
                    <th class="text-left px-6 py-3 font-medium">Status</th>
                    <th class="text-left px-6 py-3 font-medium">Comments</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-surface-100">
                @foreach($document->assignments as $a)
                    <tr>
                        <td class="px-6 py-3 font-medium text-surface-800">{{ $a->stage->stage_name }}</td>
                        <td class="px-6 py-3 text-surface-600">{{ $a->approver->full_name ?? 'Unassigned' }}</td>
                        <td class="px-6 py-3"><x-status-badge :status="$a->individual_status" /></td>
                        <td class="px-6 py-3 text-surface-500">{{ $a->comments ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
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
