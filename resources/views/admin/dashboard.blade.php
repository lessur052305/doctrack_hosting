@extends('layouts.app')
@section('title', 'Control Center')
@section('page-title', 'Admin Control Center')

@section('content')
<div class="space-y-6">
    <div id="admin-overview" data-poll-url="{{ route('admin.dashboard.poll') }}" data-refresh-url="{{ route('admin.dashboard.refresh') }}">
        @include('admin.partials.overview')
    </div>
</div>

<script>
    // Live-updates the KPI cards + SLA alerts without a full page reload —
    // instant via Reverb (see startLiveChannel in resources/js/app.js) the
    // moment any document changes status anywhere in the system; the slow
    // poll behind it is only a fallback in case the WebSocket connection
    // is down. Not scoped to one user's channel — every admin shares the
    // same 'admin-dashboard' channel, since admins see all documents.
    //
    // Wrapped in DOMContentLoaded, not a bare IIFE — see the matching
    // comment in approver/dashboard.blade.php for why: this plain inline
    // script would otherwise run before app.js's deferred module script
    // has defined startLiveChannel/startLivePoll, throw immediately, and
    // silently never wire anything up.
    document.addEventListener('DOMContentLoaded', function () {
        const overviewEl = document.getElementById('admin-overview');
        if (!overviewEl) return;

        const opts = {
            refreshUrl: overviewEl.dataset.refreshUrl,
            target: overviewEl,
        };

        startLiveChannel('admin-dashboard', '.document.status-changed', opts);
        startLivePoll({ ...opts, pollUrl: overviewEl.dataset.pollUrl });
    });
</script>
@endsection
