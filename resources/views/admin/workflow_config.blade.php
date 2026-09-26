@extends('layouts.app')
@section('title', 'Approval Workflow')
@section('page-title', 'Approval Workflow')

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-6">
            <h2 class="text-sm font-semibold text-surface-900 mb-2">About this workflow</h2>
            <p class="text-xs text-surface-500 leading-relaxed">
                These are UJF's current approval stages for each document category, set up to match the company's own procedure. Every stage except Final Approval opens as soon as a document is routed; <strong>Final Approval opens only once every other stage is approved</strong>, and only a Head Approver from the owning department(s) can sign it off.
            </p>
            <p class="text-xs text-surface-500 leading-relaxed mt-2">
                Approver deadlines are calculated automatically from the working time left before each document's own due date. This page is for viewing only.
            </p>
        </div>

        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-6 mt-6">
            <h2 class="text-sm font-semibold text-surface-900 mb-1">Approver Decision Restriction</h2>
            <p class="text-xs text-surface-500 mb-4">
                When on, an approver can only Approve/Reject during business hours (9 AM–5 PM, Mon–Sat) — this stops a document from being decided after-hours to dodge acting on it during paid working time. Off by default.
            </p>
            <form method="POST" action="{{ route('admin.systemSettings.businessHoursToggle') }}">
                @csrf
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="enforce_business_hours_decisions" value="1"
                        {{ $businessHoursEnforced ? 'checked' : '' }}
                        onchange="this.form.submit()"
                        class="sr-only peer">
                    <span class="relative w-10 h-6 bg-surface-200 peer-checked:bg-primary-700 rounded-full transition-colors">
                        <span class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform peer-checked:translate-x-4"></span>
                    </span>
                    <span class="text-xs font-medium text-surface-700">
                        Restrict approver decisions to business hours — currently <strong class="{{ $businessHoursEnforced ? 'text-primary-700' : 'text-surface-500' }}">{{ $businessHoursEnforced ? 'ON' : 'OFF' }}</strong>
                    </span>
                </label>
            </form>
        </div>
    </div>

    <div class="lg:col-span-2 space-y-6" id="workflow-config-results"
        data-poll-url="{{ route('admin.workflow.config.poll') }}" data-refresh-url="{{ route('admin.workflow.config.refresh') }}">
        @include('admin.partials.workflow-config-results')
    </div>
</div>

<script>
    // Same live-poll pattern as every other admin module — see
    // dashboard.blade.php's comment for the full reasoning. An
    // assignment decided elsewhere needs to show up here without a manual
    // reload, since the pending counts below reflect live data.
    document.addEventListener('DOMContentLoaded', function () {
        const resultsEl = document.getElementById('workflow-config-results');
        if (!resultsEl) return;

        const opts = {
            refreshUrl: resultsEl.dataset.refreshUrl,
            target: resultsEl,
        };

        startLiveChannel('admin-dashboard', '.admin.activity-logged', opts);
        startLivePoll({ ...opts, pollUrl: resultsEl.dataset.pollUrl });
    });
</script>
@endsection
