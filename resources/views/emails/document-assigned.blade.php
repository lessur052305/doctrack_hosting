@component('mail::message')
# New Document Assigned

**{{ $document->title }}** requires your review at stage **{{ $stage->stage_name }}**.

- Category: {{ $document->ml_category }}
- Due date: {{ optional($document->due_date)->toDayDateTimeString() }}

@component('mail::button', ['url' => route('approver.dashboard')])
Review Now
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
