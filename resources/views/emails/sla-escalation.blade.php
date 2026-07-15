@component('mail::message')
# SLA Breach — Admin Attention Needed

**{{ $assignment->document->title }}** has breached its SLA at stage **{{ $assignment->stage->stage_name }}**.

- Approver: {{ $assignment->approver->full_name ?? 'Unassigned' }}
- SLA expired: {{ optional($assignment->sla_expires_at)->toDayDateTimeString() }}

@component('mail::button', ['url' => route('admin.sla.queue')])
Open SLA Queue
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
