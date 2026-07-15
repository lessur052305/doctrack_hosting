@extends('layouts.app')
@section('title', 'Control Center')
@section('page-title', 'Admin Control Center')

@section('content')
<div class="space-y-6">

    {{-- KPI cards --}}
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
        @foreach([
            ['Total Documents', $stats['total_documents'], 'text-surface-900'],
            ['In Progress', $stats['pending'], 'text-processing-700'],
            ['Approved', $stats['approved'], 'text-approved-700'],
            ['Rejected', $stats['rejected'], 'text-rejected-700'],
            ['Active Users', $stats['active_users'], 'text-primary-700'],
        ] as [$label, $value, $color])
            <div class="bg-white rounded-xl shadow-card border border-surface-200 p-5">
                <p class="text-xs text-surface-500 mb-1">{{ $label }}</p>
                <p class="text-2xl font-bold {{ $color }}">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- SLA override alerts --}}
        <div class="lg:col-span-2 bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-surface-200 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-surface-900 flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-rejected-500 animate-pulse"></span>
                    SLA Override Alerts
                </h2>
                <a href="{{ route('admin.sla.queue') }}" class="text-xs text-primary-700 hover:underline font-medium">View all &rarr;</a>
            </div>
            <ul class="divide-y divide-surface-100">
                @forelse($slaAlerts->take(6) as $a)
                    <li class="px-6 py-3 flex items-center justify-between text-sm">
                        <div class="min-w-0">
                            <p class="font-medium text-surface-800 truncate">{{ $a->document->title }}</p>
                            <p class="text-xs text-rejected-700">Breached at stage "{{ $a->stage->stage_name }}" — expired <span data-live-time="{{ $a->sla_expires_at->timestamp }}">{{ $a->sla_expires_at->diffForHumans() }}</span></p>
                        </div>
                        <a href="{{ route('admin.sla.queue') }}" class="text-xs bg-primary-700 text-white px-3 py-1.5 rounded-lg font-medium hover:bg-primary-800">Override</a>
                    </li>
                @empty
                    <li class="px-6 py-8 text-center text-sm text-surface-400">No SLA breaches — everything is on schedule.</li>
                @endforelse
            </ul>
        </div>

        {{-- Active ML model --}}
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-6">
            <h2 class="text-sm font-semibold text-surface-900 mb-4">Active ML Model</h2>
            @if($activeModel)
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between"><dt class="text-surface-500">Version</dt><dd class="font-medium">{{ $activeModel->version }}</dd></div>
                    <div class="flex justify-between"><dt class="text-surface-500">Training samples</dt><dd class="font-medium">{{ $activeModel->training_sample_count }}</dd></div>
                    <div class="flex justify-between"><dt class="text-surface-500">Est. accuracy</dt><dd class="font-medium text-approved-700">{{ $activeModel->accuracy_score }}%</dd></div>
                    <div class="flex justify-between"><dt class="text-surface-500">Last trained</dt><dd class="font-medium">{{ $activeModel->last_trained->diffForHumans() }}</dd></div>
                </dl>
            @else
                <p class="text-sm text-surface-400">No model trained yet.</p>
            @endif
            <a href="{{ route('admin.ml.training') }}" class="mt-4 inline-block text-xs font-medium text-primary-700 hover:underline">Manage training data &rarr;</a>
        </div>
    </div>
</div>
@endsection