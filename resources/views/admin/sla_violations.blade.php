@extends('layouts.app')
@section('title', 'SLA Violations')
@section('page-title', 'SLA Violation Reports')

@section('content')
<div class="space-y-6">

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-5">
            <p class="text-xs text-surface-500 mb-1">Total Violations</p>
            <p class="text-2xl font-bold text-rejected-700">{{ $totalCount }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-5">
            <p class="text-xs text-surface-500 mb-1">Avg. Minutes Overdue</p>
            <p class="text-2xl font-bold text-surface-900">{{ $avgOverdue }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-5">
            <p class="text-xs text-surface-500 mb-1">Top Approver</p>
            <p class="text-sm font-semibold text-surface-900">{{ optional($byApprover->first()?->approver)->full_name ?? '—' }}</p>
            <p class="text-xs text-surface-400">{{ $byApprover->first()->total ?? 0 }} breach(es)</p>
        </div>
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-5">
            <p class="text-xs text-surface-500 mb-1">Top Bottleneck Stage</p>
            <p class="text-sm font-semibold text-surface-900">{{ $byStage->first()->stage_name ?? '—' }}</p>
            <p class="text-xs text-surface-400">{{ $byStage->first()->total ?? 0 }} breach(es)</p>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
        <form method="GET" class="px-6 py-4 border-b border-surface-200 flex flex-wrap gap-3">
            <select name="category" class="rounded-lg border-surface-300 text-xs px-3 py-2">
                <option value="">All Categories</option>
                @foreach($categories as $c)
                    <option value="{{ $c }}" {{ request('category') === $c ? 'selected' : '' }}>{{ $c }}</option>
                @endforeach
            </select>
            <input type="text" name="stage_name" value="{{ request('stage_name') }}" placeholder="Stage name…"
                class="rounded-lg border-surface-300 text-xs px-3 py-2 w-48">
            <input type="number" name="approver_id" value="{{ request('approver_id') }}" placeholder="Approver ID…"
                class="rounded-lg border-surface-300 text-xs px-3 py-2 w-36">
            <input type="date" name="date_from" value="{{ request('date_from') }}" class="rounded-lg border-surface-300 text-xs px-3 py-2">
            <input type="date" name="date_to" value="{{ request('date_to') }}" class="rounded-lg border-surface-300 text-xs px-3 py-2">
            <button class="text-xs font-medium bg-primary-700 hover:bg-primary-800 text-white px-4 py-2 rounded-lg">Filter</button>
            <a href="{{ route('admin.sla.violations') }}" class="text-xs font-medium text-surface-500 hover:underline self-center">Clear</a>
        </form>

        @php
            // Nest by document — a document can rack up several breaches
            // (one per stage, or repeat breaches over time), which
            // previously showed as separate flat rows repeating the same
            // title and made the report noisy. Same pattern as the Admin
            // dashboard's "SLA Override Alerts" widget.
            $violationsByDocument = $violations->getCollection()->groupBy('document_id');
        @endphp

        <div class="hidden sm:flex items-center gap-4 px-6 py-2 bg-surface-50 text-surface-500 text-xs uppercase tracking-wide border-b border-surface-200">
            <span class="w-36 shrink-0">Timestamp</span>
            <span class="w-32 shrink-0">Stage</span>
            <span class="w-32 shrink-0">Approver</span>
            <span>Overdue</span>
        </div>

        <div class="divide-y divide-surface-100">
            @forelse($violationsByDocument as $docViolations)
                @php $doc = $docViolations->first()->document; @endphp
                <div class="px-6 py-4">
                    <div class="flex items-center justify-between gap-3 mb-2">
                        <p class="text-sm font-semibold text-surface-900 truncate">{{ $doc->title ?? 'Deleted document' }}</p>
                        <span class="text-xs text-surface-400 shrink-0">{{ $docViolations->count() }} breach{{ $docViolations->count() === 1 ? '' : 'es' }}</span>
                    </div>
                    <ul class="space-y-1.5">
                        @foreach($docViolations as $v)
                            <li class="flex flex-wrap sm:flex-nowrap items-center gap-x-4 gap-y-1 text-xs">
                                <span class="w-36 shrink-0 text-surface-500 whitespace-nowrap">{{ $v->violation_timestamp->format('M j, Y g:i A') }}</span>
                                <span class="w-32 shrink-0 text-surface-600 truncate">{{ $v->stage_name }}</span>
                                <span class="w-32 shrink-0 text-surface-600 truncate">{{ $v->approver->full_name ?? 'Unassigned' }}</span>
                                <span class="text-rejected-700 font-medium">{{ $v->duration_overdue }}m</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <div class="px-6 py-8 text-center text-surface-400 text-sm">No SLA violations match these filters.</div>
            @endforelse
        </div>
        <div class="px-6 py-4 border-t border-surface-200">{{ $violations->links() }}</div>
    </div>
</div>
@endsection
