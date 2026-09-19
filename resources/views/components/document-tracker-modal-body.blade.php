{{--
    Body fragment for the Document Tracker popup — see routes/web.php's
    documents.trackerModal and DocumentController::trackerModal(). Fetched
    into components/kpi-drilldown-modal.blade.php's #kpi-drilldown-body
    (which is itself flex-1 overflow-auto), so this just needs to be
    exactly h-full — <x-document-tracker :fill="true"> then does its own
    internal scrolling, rather than the body scrolling too and risking a
    nested double scrollbar.
--}}
<div class="h-full flex flex-col">
    <x-document-tracker :document="$document" :fill="true" />
</div>
