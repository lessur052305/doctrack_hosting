@extends('layouts.app')
@section('title', 'Operational Calendar')
@section('page-title', 'Operational Window & Holidays')

@section('content')
@php
    $dayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $gridStart = $month->copy()->startOfMonth()->startOfWeek(\Carbon\Carbon::SUNDAY);
    $gridEnd = $month->copy()->endOfMonth()->endOfWeek(\Carbon\Carbon::SATURDAY);
    $workingDays = $settings->working_days ?? [];
@endphp

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-1 space-y-6">
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-6">
            <h2 class="text-sm font-semibold text-surface-900 mb-4">Business Hours</h2>
            <form method="POST" action="{{ route('admin.calendar.settings.update') }}" class="space-y-3">
                @csrf
                @method('PUT')
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-surface-700 mb-1">Start Time</label>
                        <input type="time" name="work_start_time" value="{{ substr($settings->work_start_time, 0, 5) }}" required class="w-full rounded-lg border-surface-300 text-sm px-3 py-2">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-surface-700 mb-1">End Time</label>
                        <input type="time" name="work_end_time" value="{{ substr($settings->work_end_time, 0, 5) }}" required class="w-full rounded-lg border-surface-300 text-sm px-3 py-2">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-surface-700 mb-2">Working Days</label>
                    <div class="grid grid-cols-4 gap-2 text-xs">
                        @foreach($dayLabels as $i => $label)
                            <label class="flex items-center gap-1.5">
                                <input type="checkbox" name="working_days[]" value="{{ $i }}" {{ in_array($i, $workingDays) ? 'checked' : '' }}>
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </div>
                <p class="text-[11px] text-surface-400">SLA countdowns only progress inside this window, on checked days. Unchecked days (Sunday, by default) are frozen identically to an admin-marked holiday.</p>
                <button class="w-full bg-primary-700 hover:bg-primary-800 text-white text-sm font-medium py-2.5 rounded-lg">Save Working Hours</button>
            </form>
        </div>
    </div>

    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-surface-200 flex items-center justify-between">
                <a href="{{ route('admin.calendar', ['month' => $month->copy()->subMonth()->format('Y-m')]) }}" class="text-sm text-primary-700 hover:underline font-medium">&larr; Prev</a>
                <h2 class="text-sm font-semibold text-surface-900">{{ $month->format('F Y') }}</h2>
                <a href="{{ route('admin.calendar', ['month' => $month->copy()->addMonth()->format('Y-m')]) }}" class="text-sm text-primary-700 hover:underline font-medium">Next &rarr;</a>
            </div>

            <table class="w-full text-xs table-fixed">
                <thead class="bg-surface-50 text-surface-500 uppercase tracking-wide">
                    <tr>
                        @foreach($dayLabels as $label)
                            <th class="text-center px-1 py-2 font-medium">{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @php $cursor = $gridStart->copy(); @endphp
                    @while($cursor->lte($gridEnd))
                        <tr class="border-t border-surface-100">
                            @for($i = 0; $i < 7; $i++)
                                @php
                                    $dateKey = $cursor->toDateString();
                                    $inMonth = $cursor->month === $month->month;
                                    $holiday = $holidays[$dateKey] ?? null;
                                    $isWorkingWeekday = in_array($cursor->dayOfWeek, $workingDays);
                                @endphp
                                <td class="align-top p-1.5 {{ $inMonth ? '' : 'opacity-30' }}">
                                    <div class="rounded-lg border {{ $holiday ? 'border-rejected-300 bg-rejected-50' : ($isWorkingWeekday ? 'border-surface-200' : 'border-surface-100 bg-surface-50') }} p-1.5 min-h-[64px]">
                                        <p class="text-[11px] font-medium text-surface-600">{{ $cursor->day }}</p>
                                        @if($holiday)
                                            <p class="text-[10px] text-rejected-700 truncate" title="{{ $holiday->label }}">{{ $holiday->label ?: 'Non-working' }}</p>
                                            <form method="POST" action="{{ route('admin.calendar.holidays.destroy', $holiday) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button class="text-[10px] text-surface-400 hover:text-rejected-700 hover:underline">Remove</button>
                                            </form>
                                        @elseif($inMonth)
                                            <form method="POST" action="{{ route('admin.calendar.holidays.store') }}">
                                                @csrf
                                                <input type="hidden" name="holiday_date" value="{{ $dateKey }}">
                                                <button class="text-[10px] text-primary-700 hover:underline">+ Mark off</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            @php $cursor->addDay(); @endphp
                            @endfor
                        </tr>
                    @endwhile
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
