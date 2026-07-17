{{--
    KPI cards + SLA Override Alerts + Active ML Model — split out from
    dashboard.blade.php so the same markup can be rendered two ways: a
    normal full page load, and a fragment returned by
    AdminController::overviewRefresh() for the live-poll JS to swap in
    place (see dashboard.blade.php) without a full page reload. The ML
    Model panel is included purely to keep the 3-column grid row (SLA
    alerts + ML model side by side) as one swap target — it rarely
    changes, but re-rendering it each cycle is harmless.
--}}
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
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

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-6">
    {{-- SLA override alerts --}}
    <div class="lg:col-span-2 bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-surface-200 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-surface-900 flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-rejected-500 animate-pulse"></span>
                SLA Override Alerts
            </h2>
            <a href="{{ route('admin.sla.queue') }}" class="text-xs text-primary-700 hover:underline font-medium">View all &rarr;</a>
        </div>
        @php
            // Nest by document — a single document can have more than one
            // breached stage (e.g. Budget Check and Final Approval both
            // pending on the same doc), which previously showed as
            // separate flat rows repeating the same title.
            $alertsByDocument = $slaAlerts->groupBy('document_id')->take(5);
        @endphp
        <ul class="divide-y divide-surface-100">
            @forelse($alertsByDocument as $breaches)
                @php $doc = $breaches->first()->document; @endphp
                <li class="px-6 py-3">
                    <div class="flex items-center justify-between gap-3">
                        <p class="font-medium text-surface-800 truncate">{{ $doc->title }}</p>
                        <a href="{{ route('admin.sla.queue') }}" class="shrink-0 text-xs bg-primary-700 text-white px-3 py-1.5 rounded-lg font-medium hover:bg-primary-800">Override</a>
                    </div>
                    <ul class="mt-1.5 space-y-1">
                        @foreach($breaches as $a)
                            <li class="text-xs text-rejected-700 flex items-center gap-1.5">
                                <span class="w-1 h-1 rounded-full bg-rejected-500 shrink-0"></span>
                                <span>Stage "{{ $a->stage->stage_name }}" — expired <span data-live-time="{{ $a->sla_expires_at->timestamp }}">{{ $a->sla_expires_at->diffForHumans() }}</span></span>
                            </li>
                        @endforeach
                    </ul>
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
