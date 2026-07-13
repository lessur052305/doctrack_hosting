@extends('layouts.app')
@section('title', 'SLA Overrides')
@section('page-title', 'SLA Override Queue')

@section('content')
<div class="space-y-4">
    <div class="rounded-lg bg-processing-50 border border-processing-500/30 text-processing-700 px-4 py-3 text-xs">
        These assignments breached their SLA deadline and were escalated for Admin review. If left unresolved for the configured grace period, the system will auto-approve them and notify all Admins &amp; Approvers.
    </div>

    @forelse($assignments as $a)
        <div class="bg-white rounded-xl shadow-card border border-rejected-500/20 p-6 flex flex-col sm:flex-row gap-4">
            <div class="flex-1 min-w-0">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-rejected-50 text-rejected-700 ring-1 ring-inset ring-rejected-500/20 mb-2">SLA Breached</span>
                <h3 class="text-sm font-semibold text-surface-900">{{ $a->document->title }}</h3>
                <p class="text-xs text-surface-500 mt-1">
                    Stage: {{ $a->stage->stage_name }} &middot;
                    Approver: {{ $a->approver->full_name ?? 'Unassigned' }} &middot;
                    Expired {{ $a->sla_expires_at->diffForHumans() }}
                </p>
            </div>
            <form method="POST" action="{{ route('admin.sla.override', $a) }}" class="flex flex-col sm:w-72 gap-2">
                @csrf
                <textarea name="comments" rows="1" placeholder="Override reason (optional)…"
                    class="w-full rounded-lg border-surface-300 text-xs focus:border-primary-500 focus:ring-primary-500 px-3 py-2"></textarea>
                <div class="flex gap-2">
                    <button name="decision" value="approved" class="flex-1 bg-approved-500 hover:bg-approved-700 text-white text-xs font-semibold py-2 rounded-lg">Override: Approve</button>
                    <button name="decision" value="rejected" class="flex-1 bg-rejected-500 hover:bg-rejected-700 text-white text-xs font-semibold py-2 rounded-lg">Override: Reject</button>
                </div>
            </form>
        </div>
    @empty
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-12 text-center">
            <p class="text-sm text-surface-500">No SLA breaches pending override.</p>
        </div>
    @endforelse

    @if($assignments->hasPages())
        <div>{{ $assignments->links() }}</div>
    @endif
</div>
@endsection
