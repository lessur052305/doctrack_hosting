@extends('layouts.app')
@section('title', 'Review Queue')
@section('page-title', 'Review Queue')

@section('content')
<div class="space-y-4">
    @forelse($assignments as $a)
        @php
            $priorityMap = [1 => ['Urgent', 'bg-rejected-50 text-rejected-700 ring-rejected-500/20'],
                             2 => ['Normal', 'bg-processing-50 text-processing-700 ring-processing-500/20'],
                             3 => ['Low', 'bg-surface-100 text-surface-600 ring-surface-300']];
            [$pLabel, $pClass] = $priorityMap[$a->priority_rank] ?? $priorityMap[2];
        @endphp
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-6 flex flex-col sm:flex-row sm:items-center gap-4">
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold ring-1 ring-inset {{ $pClass }}">{{ $pLabel }}</span>
                    <span class="text-xs text-surface-400">Stage: {{ $a->stage->stage_name }}</span>
                </div>
                <h3 class="text-sm font-semibold text-surface-900 truncate">{{ $a->document->title }}</h3>
                <p class="text-xs text-surface-500 mt-1">
                    Category: {{ $a->document->ml_category }} &middot;
                    Submitted by {{ $a->document->originator->full_name }} &middot;
                    <a href="#" class="text-primary-700 hover:underline" onclick="document.getElementById('doc-preview-{{ $a->assignment_id }}').classList.toggle('hidden'); return false;">View extracted text</a>
                </p>
                <div id="doc-preview-{{ $a->assignment_id }}" class="hidden mt-2 text-xs text-surface-600 bg-surface-50 rounded-lg p-3 max-h-32 overflow-y-auto">
                    {{ \Illuminate\Support\Str::limit($a->document->ocr_text, 600) }}
                </div>
            </div>

            <div class="text-center sm:text-right sm:w-40">
                <p class="text-xs text-surface-400 mb-1">SLA expires</p>
                <p class="text-sm font-semibold {{ $a->seconds_remaining !== null && $a->seconds_remaining < 3600 ? 'text-rejected-700' : 'text-surface-700' }}"
                   data-countdown="{{ optional($a->sla_expires_at)->timestamp }}">
                    {{ $a->sla_expires_at?->diffForHumans() ?? '—' }}
                </p>
            </div>

            <form method="POST" action="{{ route('approver.assignments.decide', $a) }}" class="flex flex-col sm:w-64 gap-2">
                @csrf
                <textarea name="comments" rows="1" placeholder="Optional comments…"
                    class="w-full rounded-lg border-surface-300 text-xs focus:border-primary-500 focus:ring-primary-500 px-3 py-2"></textarea>
                <div class="flex gap-2">
                    <button type="submit" name="decision" value="approved"
                        class="flex-1 bg-approved-500 hover:bg-approved-700 text-white text-xs font-semibold py-2 rounded-lg transition-colors">
                        Approve
                    </button>
                    <button type="submit" name="decision" value="rejected"
                        class="flex-1 bg-rejected-500 hover:bg-rejected-700 text-white text-xs font-semibold py-2 rounded-lg transition-colors">
                        Reject
                    </button>
                </div>
            </form>
        </div>
    @empty
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-12 text-center">
            <p class="text-sm text-surface-500">Your queue is clear — no pending documents right now.</p>
        </div>
    @endforelse

    @if($assignments->hasPages())
        <div class="pt-2">{{ $assignments->links() }}</div>
    @endif
</div>
@endsection
