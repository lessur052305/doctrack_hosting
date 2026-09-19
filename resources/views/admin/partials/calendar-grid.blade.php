{{--
    Calendar grid — split out from calendar.blade.php so the same markup
    can be rendered two ways: a normal full page load, and a fragment
    returned by AdminController::calendarRefresh() for the live-poll JS to
    swap in place, without a full page reload.
--}}
@php
    $dayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $gridStart = $month->copy()->startOfMonth()->startOfWeek(\Carbon\Carbon::SUNDAY);
    $gridEnd = $month->copy()->endOfMonth()->endOfWeek(\Carbon\Carbon::SATURDAY);
    // Fixed operational window (9-5, Mon-Sat) — no longer admin-editable,
    // see config/sla.php. Only holiday marking is still admin-controlled.
    $workingDays = config('sla.default_working_days');
@endphp

{{-- Capped to the device's own viewport height, and stretched to fill it
     (Feature: no more leftover white space below a 5-week month). Height
     is set by JS (calendar.blade.php calls resources/js/app.js's
     sizeCappedCard() on this card's #calendar-card id) rather than a
     static class — this whole card is the live-refresh fragment's own
     root (the swap target is #calendar-wrapper, the plain div around it
     in calendar.blade.php), so it's a fresh element after every swap and
     has to be re-measured every time, not just once on load. Unlike a
     paginated list, nothing here can be hidden to fit — every day has to
     stay visible — so instead of initFittedPagination() this just gives
     the <table> h-full (flex-1 below stretches the wrapper around it) and
     lets the browser's own table layout distribute the extra height
     evenly across however many week-rows exist (5 or 6 depending on the
     month), rather than sizing rows by content and leaving the remainder
     blank underneath. --}}
<div id="calendar-card" class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden flex flex-col">
    <div class="px-6 py-4 border-b border-surface-200 flex items-center justify-between flex-shrink-0">
        <a href="{{ route('admin.calendar', ['month' => $month->copy()->subMonth()->format('Y-m')]) }}" class="text-base text-primary-700 hover:underline font-medium">&larr; Prev</a>
        <div class="text-center">
            <h2 class="text-lg font-semibold text-surface-900">{{ $month->format('F Y') }}</h2>
            <p class="text-sm text-surface-400 mt-0.5">
                Working hours: {{ \Carbon\Carbon::parse(config('sla.default_work_start'))->format('g:i A') }}&ndash;{{ \Carbon\Carbon::parse(config('sla.default_work_end'))->format('g:i A') }},
                {{ collect($workingDays)->sort()->map(fn ($d) => $dayLabels[$d])->implode(', ') }}
            </p>
        </div>
        <a href="{{ route('admin.calendar', ['month' => $month->copy()->addMonth()->format('Y-m')]) }}" class="text-base text-primary-700 hover:underline font-medium">Next &rarr;</a>
    </div>

    <div class="overflow-x-auto flex-1 min-h-0">
    <table class="w-full h-full min-w-[560px] text-sm table-fixed">
        <thead class="bg-surface-50 text-surface-500 uppercase tracking-wide">
            <tr>
                @foreach($dayLabels as $label)
                    <th class="text-center px-1 py-2 font-medium text-sm">{{ $label }}</th>
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
                            // Feature: a day that's already happened can't
                            // be marked off — doing so wouldn't change
                            // anything real (SLA calculations only ever
                            // look forward), so offering the control there
                            // was just confusing. Covers every day of a
                            // past month too, not only earlier days in the
                            // current one — lt(today()) is true for all of
                            // them regardless of which month is on screen.
                            $isPast = $cursor->lt(today());
                        @endphp
                        <td class="align-top p-1.5 {{ $inMonth ? '' : 'opacity-30' }}">
                            {{-- h-full (on top of the min-h floor) is what
                                 actually fills the row's real height once
                                 the table itself stretches (see the
                                 h-full <table> above) — without it, this
                                 box would stay pinned to its old min-height
                                 even in a much taller row, leaving the
                                 gain sit as visible padding inside the
                                 cell instead of the box itself growing. --}}
                            <div class="rounded-lg border {{ $holiday ? 'border-rejected-300 bg-rejected-50' : ($isWorkingWeekday ? 'border-surface-200' : 'border-surface-100 bg-surface-50') }} p-1.5 min-h-[64px] h-full">
                                <button type="button"
                                    onclick="openKpiDrilldown('date', '{{ $cursor->format('M j, Y') }}', '{{ route('admin.calendar.documentsOnDate', $dateKey) }}')"
                                    class="text-sm font-medium text-surface-600 hover:text-primary-700 hover:underline">
                                    {{ $cursor->day }}
                                </button>
                                @if($holiday)
                                    <p class="text-xs text-rejected-700 truncate" title="{{ $holiday->label }}">{{ $holiday->label ?: 'Non-working' }}</p>
                                    {{-- bg + padding, not just colored underlined
                                         text — reads as a clickable control at a
                                         glance, same treatment as the Resubmit
                                         button on the Document Tracker page. --}}
                                    <form method="POST" action="{{ route('admin.calendar.holidays.destroy', $holiday) }}" class="mt-1">
                                        @csrf
                                        @method('DELETE')
                                        <button class="inline-flex items-center px-2 py-1 rounded-lg bg-surface-100 hover:bg-rejected-100 text-surface-500 hover:text-rejected-700 text-xs font-medium transition-colors">Remove</button>
                                    </form>
                                @elseif($inMonth && !$isPast)
                                    <form method="POST" action="{{ route('admin.calendar.holidays.store') }}" class="mt-1">
                                        @csrf
                                        <input type="hidden" name="holiday_date" value="{{ $dateKey }}">
                                        <button class="inline-flex items-center px-2 py-1 rounded-lg bg-primary-50 hover:bg-primary-100 text-primary-700 text-xs font-medium transition-colors">+ Mark off</button>
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
