@extends('layouts.app')
@section('title', 'Audit Logs')
@section('page-title', 'Audit Trail')

@section('content')
@php
    // Five visually distinct buckets so the page can be scanned at a
    // glance instead of every action looking like the same gray pill.
    $actionCategories = [
        'login' => 'session', 'logout' => 'session',
        'upload' => 'lifecycle', 'extraction_failed' => 'lifecycle', 'classify' => 'lifecycle',
        'validate' => 'lifecycle', 'route' => 'lifecycle', 'route_no_approver' => 'lifecycle',
        'finalize' => 'lifecycle', 'legacy_import' => 'lifecycle', 'archive_download' => 'lifecycle',
        'approved' => 'approved', 'rejected' => 'rejected',
        'admin_override' => 'escalation', 'auto_approve' => 'escalation', 'sla_escalation' => 'escalation',
    ];
    $categoryClasses = [
        'session' => 'bg-surface-100 text-surface-500',
        'lifecycle' => 'bg-primary-50 text-primary-700',
        'approved' => 'bg-approved-50 text-approved-700',
        'rejected' => 'bg-rejected-50 text-rejected-700',
        'escalation' => 'bg-processing-50 text-processing-700',
        'config' => 'bg-indigo-50 text-indigo-700', // default: workflow_config, sla_settings_update, sla_holiday_*, due_date_adjusted, sla_recalculated, user_create, user_toggle, assign_stages, ml_train
    ];
@endphp

<div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
    <form method="GET" class="px-6 py-4 border-b border-surface-200 space-y-3">
        <div class="relative">
            <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-surface-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
            </svg>
            <input type="text" id="document-search" name="document" value="{{ request('document') }}"
                placeholder="Search document — by title or #ID" autocomplete="off"
                class="w-full rounded-lg border-surface-300 text-sm pl-9 pr-3 py-2.5 focus:border-primary-500 focus:ring-primary-500">
        </div>
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-[11px] font-medium text-surface-500 mb-1">Action</label>
                <select name="action_type" class="rounded-lg border-surface-300 text-xs px-3 py-2">
                    <option value="">All Actions</option>
                    @foreach($actionTypes as $type)
                        <option value="{{ $type }}" {{ request('action_type') === $type ? 'selected' : '' }}>{{ $type }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-[11px] font-medium text-surface-500 mb-1">Actor</label>
                <select name="actor_id" class="rounded-lg border-surface-300 text-xs px-3 py-2">
                    <option value="">All Actors</option>
                    @foreach($actors as $actor)
                        <option value="{{ $actor->user_id }}" {{ (int) request('actor_id') === $actor->user_id ? 'selected' : '' }}>{{ $actor->full_name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-[11px] font-medium text-surface-500 mb-1">From</label>
                <input type="date" name="date_from" value="{{ request('date_from') }}" class="rounded-lg border-surface-300 text-xs px-3 py-2">
            </div>
            <div>
                <label class="block text-[11px] font-medium text-surface-500 mb-1">To</label>
                <input type="date" name="date_to" value="{{ request('date_to') }}" class="rounded-lg border-surface-300 text-xs px-3 py-2">
            </div>
            <label class="flex items-center gap-1.5 text-xs text-surface-600 pb-2.5">
                <input type="checkbox" name="show_session" value="1" {{ request('show_session') ? 'checked' : '' }}>
                Show login/logout events
            </label>
            <button class="text-xs font-medium bg-primary-700 hover:bg-primary-800 text-white px-4 py-2 rounded-lg">Filter</button>
            @if(request()->anyFilled(['document', 'action_type', 'actor_id', 'date_from', 'date_to', 'show_session']))
                <a href="{{ route('admin.audit.logs') }}" class="text-xs font-medium text-surface-500 hover:underline pb-2.5">Clear</a>
            @endif
        </div>
    </form>

    <div class="overflow-x-auto">
    <table class="w-full min-w-[720px] text-sm">
        <thead class="bg-surface-50 text-surface-500 text-xs uppercase tracking-wide">
            <tr>
                <th class="text-left px-6 py-3 font-medium">Timestamp</th>
                <th class="text-left px-6 py-3 font-medium">Actor</th>
                <th class="text-left px-6 py-3 font-medium">Action</th>
                <th class="text-left px-6 py-3 font-medium">Document</th>
                <th class="text-left px-6 py-3 font-medium">Description</th>
            </tr>
        </thead>
        <tbody id="audit-rows" class="divide-y divide-surface-100">
            @forelse($logs as $log)
                @php
                    $category = $actionCategories[$log->action_type] ?? 'config';
                    $badgeClass = $categoryClasses[$category];
                @endphp
                <tr class="audit-row hover:bg-surface-50" data-document-title="{{ strtolower($log->document->title ?? '') }}" data-document-id="{{ $log->document_id }}">
                    <td class="px-6 py-3 text-surface-500 whitespace-nowrap align-top">{{ $log->timestamp->format('M j, Y g:i:s A') }}</td>
                    <td class="px-6 py-3 text-surface-700 align-top">{{ $log->user->full_name ?? 'System' }}</td>
                    <td class="px-6 py-3 align-top">
                        <span class="text-xs font-semibold px-2 py-0.5 rounded-full {{ $badgeClass }}">{{ $log->action_type }}</span>
                    </td>
                    <td class="px-6 py-3 text-surface-500 align-top whitespace-nowrap">
                        @if($log->document_id && $log->document)
                            <a href="{{ route('documents.track', $log->document) }}" class="text-primary-700 hover:underline font-medium">#{{ $log->document_id }}</a>
                        @elseif($log->document_id)
                            #{{ $log->document_id }}
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-6 py-3 text-surface-600 align-top">{{ $log->description }}</td>
                </tr>
            @empty
                <tr id="audit-empty">
                    <td colspan="5" class="px-6 py-10 text-center text-surface-400 text-sm">No audit entries match these filters.</td>
                </tr>
            @endforelse
            <tr id="audit-no-matches" class="hidden">
                <td colspan="5" class="px-6 py-10 text-center text-surface-400 text-sm">No entries on this page match "<span id="audit-no-matches-term"></span>". Press Enter to search every page.</td>
            </tr>
        </tbody>
    </table>
    </div>
    <div class="px-6 py-4 border-t border-surface-200">{{ $logs->links() }}</div>
</div>

<script>
    // Real-time, client-side filter over the rows already rendered on this
    // page — instant, no round trip. Pressing Enter still submits the
    // surrounding <form> normally, running the "document" filter
    // server-side (see AdminController::auditLogs()) across every page.
    // Also auto-submits the "Show login/logout events" checkbox so it
    // takes effect immediately without a separate button press.
    (function () {
        const input = document.getElementById('document-search');
        const rows = Array.from(document.querySelectorAll('.audit-row'));
        const noMatches = document.getElementById('audit-no-matches');
        const noMatchesTerm = document.getElementById('audit-no-matches-term');
        const sessionToggle = document.querySelector('input[name="show_session"]');

        if (sessionToggle) {
            sessionToggle.addEventListener('change', () => sessionToggle.form.submit());
        }

        if (!input || rows.length === 0) return;

        input.addEventListener('input', () => {
            const term = input.value.trim().toLowerCase();
            const idTerm = term.replace(/^#/, '');
            let visibleCount = 0;

            rows.forEach((row) => {
                const matches = term === ''
                    || row.dataset.documentTitle.includes(term)
                    || (idTerm !== '' && row.dataset.documentId === idTerm);
                row.classList.toggle('hidden', !matches);
                if (matches) visibleCount++;
            });

            const showNoMatches = term !== '' && visibleCount === 0;
            noMatches.classList.toggle('hidden', !showNoMatches);
            if (showNoMatches) noMatchesTerm.textContent = input.value.trim();
        });
    })();
</script>
@endsection
