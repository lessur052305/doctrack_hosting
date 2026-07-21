@component('mail::message')
# Your auto-approved document was disputed

**{{ $document->title }}** was automatically approved by the system at {{ count($stageNames) === 1 ? 'stage' : 'stages' }} **{{ implode(', ', $stageNames) }}** after no one acted in time. An Admin has since reviewed it and found a problem:

> {{ $reason }}

This document's approval is not being reversed automatically. Please resubmit a corrected version so it can go through the normal review process.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
