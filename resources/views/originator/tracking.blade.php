@extends('layouts.app')
@section('title', 'Track Document')
@section('page-title', 'Document Tracking')

@section('content')
{{-- Feature: a way back without relying on the sidebar or the browser's
     own back button — this page is always reached by clicking INTO
     something from a list (Your Submissions for an Originator; the
     Document Tracking module or Audit Logs for an Admin, who can inspect
     any document — see routes/web.php's documents.track comment), never
     from the sidebar directly, so it's the one place in the app that
     genuinely needs an explicit return link. Admin's two possible origins
     are disambiguated by a `from` query param the caller links with (see
     admin/partials/audit-row.blade.php); Document Tracking is the default
     since it's the more common path in. An Originator only ever has one
     possible origin, so no param needed there. --}}
@php
    $backTarget = auth()->user()->isOriginator()
        ? ['label' => 'Your Submissions', 'url' => route('originator.dashboard')]
        : (request('from') === 'audit'
            ? ['label' => 'Audit Logs', 'url' => route('admin.audit.logs')]
            : ['label' => 'Document Tracking', 'url' => route('admin.documents.index')]);
@endphp
{{-- Solid, not tinted — a light bg-primary-50 fill still read as barely
     there against this page's own bg-surface-50 background (see
     layouts/app.blade.php's <main>), so this uses the same solid fill as
     a real primary action button (Save, Add to Archive) instead of the
     lighter tint used for the smaller in-row buttons elsewhere. --}}
<a href="{{ $backTarget['url'] }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-primary-700 hover:bg-primary-800 text-sm font-medium text-white transition-colors mb-4 shadow-sm">
    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
    Back to {{ $backTarget['label'] }}
</a>
<div id="tracking-content"
    data-document-id="{{ $document->document_id }}"
    data-originator-id="{{ $document->originator_id }}"
    data-poll-url="{{ route('originator.documents.trackingPoll', $document) }}"
    data-refresh-url="{{ route('originator.documents.trackingRefresh', $document) }}">
    @include('originator.partials.tracking-content')
</div>

<script>
    // Live-updates this document's status/stages/audit trail without a
    // full page reload — instant via Reverb the moment any stage on THIS
    // document is decided or its overall status changes (not just full
    // approval/rejection — a single stage being approved mid-pipeline
    // updates the Approval Stages list here too); the slow poll behind it
    // is only a fallback in case the WebSocket connection is down. See
    // startLiveChannel()/startLivePoll() in resources/js/app.js, and the
    // matching comment in approver/dashboard.blade.php for why this is
    // wrapped in DOMContentLoaded rather than a bare IIFE.
    // Document Tracker's own scroll area (see tracking-content.blade.php)
    // is sized to exactly fill the space left over below the document
    // header card, so a long tracker scrolls internally instead of pushing
    // <main> — this app's real scroll container, see layouts/app.blade.php
    // — into scrolling the whole page. Measured live against <main>'s own
    // bottom edge rather than a fixed calc(), since the header card above
    // it varies in height (version badge, resubmit form, Imported/
    // Superseded notices, ...) — a fixed number would only be correct for
    // whichever card height happened to be on screen when it was written.
    function sizeDocumentTracker() {
        const scrollEl = document.getElementById('document-tracker-scroll');
        const mainEl = document.querySelector('main');
        if (!scrollEl || !mainEl) return;

        // <main>'s own bottom padding (p-4 sm:p-8) sits between its
        // content and its measured bottom edge — read live via
        // getComputedStyle rather than hardcoded, so this stays correct if
        // that padding class ever changes, instead of quietly drifting out
        // of sync again the way a flat buffer alone previously did (it
        // left the tracker ~24px too tall, just enough to force <main>
        // itself into scrolling on top of the tracker's own internal one).
        const mainPaddingBottom = parseFloat(getComputedStyle(mainEl).paddingBottom) || 0;
        const available = mainEl.getBoundingClientRect().bottom - mainPaddingBottom - scrollEl.getBoundingClientRect().top;
        // Floor guard so a very tall header card never collapses this to
        // something unusably short.
        scrollEl.style.maxHeight = Math.max(available - 8, 200) + 'px';
    }

    // Same technique as sizeDocumentTracker() just above, pointed at the
    // RIGHT column instead (Approval Stages, plus Revision Requests once
    // there's anything open). That column used to have no height cap at
    // all — fine while it only ever held Approval Stages, but adding a
    // whole extra Revision Requests card underneath it (Feature: editable
    // document) could make it tall enough to outgrow the viewport, and
    // with no cap of its own <main> — the real page scroll container —
    // was the thing that ended up scrolling instead of just this column.
    function sizeTrackingRightColumn() {
        const columnEl = document.getElementById('tracking-right-column');
        const mainEl = document.querySelector('main');
        if (!columnEl || !mainEl) return;

        const mainPaddingBottom = parseFloat(getComputedStyle(mainEl).paddingBottom) || 0;
        const available = mainEl.getBoundingClientRect().bottom - mainPaddingBottom - columnEl.getBoundingClientRect().top;
        columnEl.style.maxHeight = Math.max(available, 200) + 'px';
    }

    function sizeTrackingLayout() {
        sizeDocumentTracker();
        sizeTrackingRightColumn();
    }

    document.addEventListener('DOMContentLoaded', function () {
        const contentEl = document.getElementById('tracking-content');
        if (!contentEl) return;

        sizeTrackingLayout();
        window.addEventListener('resize', sizeTrackingLayout);

        const thisDocumentId = parseInt(contentEl.dataset.documentId, 10);

        const opts = {
            refreshUrl: contentEl.dataset.refreshUrl,
            target: contentEl,
            // The originator's channel carries events for ALL of their
            // documents, not just this one — only react when the event is
            // actually about the document this page is showing.
            filter: (data) => data.document_id === thisDocumentId,
            // The live swap replaces #tracking-content's whole innerHTML
            // (new header card content, new tracker rows, and possibly a
            // Revision Requests card appearing/disappearing) — re-measure
            // both columns afterward, not just once on initial load.
            onSwap: sizeTrackingLayout,
        };

        // Subscribed to the document's actual owner's channel, not the
        // current viewer's own id — for the originator viewing their own
        // document these are the same person, but an Admin (or anyone else
        // permitted onto this page) viewing someone ELSE's document has a
        // different id from the owner, and the server only ever broadcasts
        // document.status-changed on the owner's channel (see
        // DocumentStatusChanged::broadcastOn()). Subscribing to the
        // viewer's own id there would silently never receive anything,
        // leaving that viewer stuck on the slow poll fallback only.
        startLiveChannel(`originator.${contentEl.dataset.originatorId}`, '.document.status-changed', opts);
        startLivePoll({ ...opts, pollUrl: contentEl.dataset.pollUrl });

        // Heads-up only, not authoritative — same check as the main
        // upload form's (see originator/dashboard.blade.php's matching
        // comment for the full reasoning). Delegated on #tracking-content
        // — the stable wrapper that survives every live swap above —
        // rather than bound directly to the input, which gets replaced
        // wholesale on every swap along with the rest of this fragment.
        const businessHoursConfig = JSON.parse(document.querySelector('meta[name="business-hours"]')?.content || 'null');

        function isWithinWorkingHours(date) {
            if (!businessHoursConfig || isNaN(date.getTime())) return true;
            if (!businessHoursConfig.workingDays.includes(date.getDay())) return false;

            const y = date.getFullYear();
            const m = String(date.getMonth() + 1).padStart(2, '0');
            const d = String(date.getDate()).padStart(2, '0');
            if (businessHoursConfig.holidays.includes(`${y}-${m}-${d}`)) return false;

            const minutes = date.getHours() * 60 + date.getMinutes();
            return minutes >= businessHoursConfig.startMinutes && minutes < businessHoursConfig.endMinutes;
        }

        contentEl.addEventListener('change', function (e) {
            const input = e.target.closest('#resubmit-due-date');
            if (!input) return;
            const warning = contentEl.querySelector('#resubmit-due-date-warning');
            if (!warning) return;
            const valid = !input.value || isWithinWorkingHours(new Date(input.value));
            warning.classList.toggle('hidden', valid);
        });

        // Checking a "Revision Requests" flag (Feature: jump straight to
        // the exact flagged passage in the text below, instead of the
        // originator having to hunt for it themselves — a real problem
        // once the same short phrase appears more than once in a
        // document). Delegated on #tracking-content, not bound to the
        // checkboxes directly, for the same reason as every other
        // listener in this block — they get replaced wholesale on every
        // live swap. tracking-content.blade.php itself can't carry this
        // as an inline <script> for that same reason: a script tag inside
        // markup that later gets swapped in via innerHTML never runs.
        contentEl.addEventListener('change', function (e) {
            const checkbox = e.target.closest('input[name="resolved_annotation_ids[]"]');
            if (!checkbox || !checkbox.checked) return;

            const textarea = document.getElementById('revision-text-editor');
            const start = Number(checkbox.dataset.start);
            const end = Number(checkbox.dataset.end);
            if (!textarea || Number.isNaN(start) || Number.isNaN(end)) return;

            // Approximate scroll position — counts newlines before the
            // flagged passage to estimate a line number. This textarea
            // wraps long lines rather than using white-space: pre, so a
            // very long unbroken line can throw the estimate off by a
            // little; setSelectionRange just below is what actually has
            // to be exact (a native character-offset selection, unaffected
            // by wrapping), this is only getting it roughly into view.
            const linesBefore = textarea.value.slice(0, start).split('\n').length - 1;
            const lineHeight = parseFloat(getComputedStyle(textarea).lineHeight) || 20;
            textarea.scrollTop = Math.max(0, lineHeight * linesBefore - textarea.clientHeight / 2);

            textarea.focus();
            textarea.setSelectionRange(start, end);
        });
    });
</script>
@endsection
