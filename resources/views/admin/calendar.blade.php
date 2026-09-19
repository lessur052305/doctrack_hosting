@extends('layouts.app')
@section('title', 'Operational Calendar')
@section('page-title', 'Operational Window & Holidays')

@section('content')
<div id="calendar-wrapper"
    data-poll-url="{{ route('admin.calendar.poll', ['month' => $month->format('Y-m')]) }}"
    data-refresh-url="{{ route('admin.calendar.refresh', ['month' => $month->format('Y-m')]) }}">
    @include('admin.partials.calendar-grid')
</div>

<script>
    // Same live-poll pattern as every other admin module — see
    // dashboard.blade.php's comment for the full reasoning. A holiday
    // marked/removed by another admin, viewing the same month, needs to
    // show up without a manual reload. Poll/refresh URLs are scoped to the
    // currently-viewed month at render time (Prev/Next are normal link
    // navigations, not AJAX, so there's no in-page month state to track).
    document.addEventListener('DOMContentLoaded', function () {
        const wrapperEl = document.getElementById('calendar-wrapper');
        if (!wrapperEl) return;

        // See resources/js/app.js's sizeCappedCard() docblock. #calendar-
        // card is the swap fragment's own root (a fresh element after
        // every live swap — see admin/partials/calendar-grid.blade.php),
        // so this has to re-run every swap too, not just once on load.
        function resizeCalendarCard() {
            sizeCappedCard(document.getElementById('calendar-card'));
        }
        resizeCalendarCard();
        window.addEventListener('resize', resizeCalendarCard);

        const opts = {
            refreshUrl: wrapperEl.dataset.refreshUrl,
            target: wrapperEl,
            onSwap: resizeCalendarCard,
        };

        startLiveChannel('admin-dashboard', '.admin.activity-logged', opts);
        startLivePoll({ ...opts, pollUrl: wrapperEl.dataset.pollUrl });
    });
</script>
@endsection
