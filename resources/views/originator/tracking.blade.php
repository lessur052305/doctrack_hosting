@extends('layouts.app')
@section('title', 'Track Document')
@section('page-title', 'Document Tracking')

@section('content')
<div class="max-w-4xl mx-auto space-y-6" id="tracking-content"
    data-document-id="{{ $document->document_id }}"
    data-user-id="{{ auth()->id() }}"
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
    document.addEventListener('DOMContentLoaded', function () {
        const contentEl = document.getElementById('tracking-content');
        if (!contentEl) return;

        const thisDocumentId = parseInt(contentEl.dataset.documentId, 10);

        const opts = {
            refreshUrl: contentEl.dataset.refreshUrl,
            target: contentEl,
            // The originator's channel carries events for ALL of their
            // documents, not just this one — only react when the event is
            // actually about the document this page is showing.
            filter: (data) => data.document_id === thisDocumentId,
        };

        startLiveChannel(`originator.${contentEl.dataset.userId}`, '.document.status-changed', opts);
        startLivePoll({ ...opts, pollUrl: contentEl.dataset.pollUrl });
    });
</script>
@endsection
