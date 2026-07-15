@component('mail::message')
# Document {{ ucfirst($decision) }}

Stage **{{ $stageName }}** of **{{ $document->title }}** was **{{ $decision }}**.

@if($comments)
> {{ $comments }}
@endif

@component('mail::button', ['url' => route('originator.documents.show', $document)])
View Document
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
