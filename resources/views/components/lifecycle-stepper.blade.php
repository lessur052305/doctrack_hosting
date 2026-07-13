@props(['document'])

@php
    $steps = [
        'processing' => 'Submitted',
        'classified_validated' => 'Classified & Validated',
        'approved' => 'Approved',
    ];
    $order = array_keys($steps);

    $status = $document->global_status;
    $isRejected = $status === 'rejected';

    // Normalize auto_approved -> approved for stepper positioning.
    $effective = in_array($status, ['approved', 'auto_approved']) ? 'approved' : $status;
    $found = array_search($effective, $order);
    $currentIndex = $isRejected ? 1 : ($found === false ? 0 : $found);
@endphp

<div class="flex items-center w-full">
    @foreach($order as $i => $key)
        @php
            $isComplete = $i < $currentIndex;
            $isCurrent  = $i === $currentIndex;
        @endphp
        <div class="flex items-center {{ $i < count($order) - 1 ? 'flex-1' : '' }}">
            <div class="flex flex-col items-center gap-1">
                <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold ring-2
                    @if($isComplete) bg-approved-500 text-white ring-approved-500
                    @elseif($isCurrent && $isRejected) bg-rejected-500 text-white ring-rejected-500
                    @elseif($isCurrent) bg-processing-500 text-white ring-processing-500
                    @else bg-surface-100 text-surface-400 ring-surface-200 @endif">
                    @if($isComplete)
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    @else
                        {{ $i + 1 }}
                    @endif
                </div>
                <span class="text-[11px] font-medium text-surface-500 whitespace-nowrap">{{ $steps[$key] }}</span>
            </div>
            @if($i < count($order) - 1)
                <div class="flex-1 h-0.5 mx-2 {{ $i < $currentIndex ? 'bg-approved-500' : 'bg-surface-200' }}"></div>
            @endif
        </div>
    @endforeach
</div>

@if($isRejected)
    <p class="mt-2 text-xs font-medium text-rejected-700">This document was rejected during review.</p>
@endif
