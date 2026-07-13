@props(['status'])

@php
    $map = [
        'processing' => ['bg-processing-50 text-processing-700 ring-processing-500/20', 'Processing'],
        'classified_validated' => ['bg-processing-50 text-processing-700 ring-processing-500/20', 'Awaiting Approval'],
        'approved' => ['bg-approved-50 text-approved-700 ring-approved-500/20', 'Approved'],
        'auto_approved' => ['bg-approved-50 text-approved-700 ring-approved-500/20', 'Auto-Approved'],
        'rejected' => ['bg-rejected-50 text-rejected-700 ring-rejected-500/20', 'Rejected'],
        'pending' => ['bg-processing-50 text-processing-700 ring-processing-500/20', 'Pending'],
    ];
    [$classes, $label] = $map[$status] ?? ['bg-surface-100 text-surface-600 ring-surface-300', ucfirst($status)];
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold ring-1 ring-inset $classes"]) }}>
    <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
    {{ $label }}
</span>
