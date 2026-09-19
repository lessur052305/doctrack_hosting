{{--
    Extracted from approver/history.blade.php so ApprovalController::
    historyRefresh() can return exactly this fragment for the live-search/
    live-poll JS to swap in, without re-rendering the whole page.
    Expects: $decisions — a paginator of containers, one per document:
    (object) ['document' => ..., 'decisions' => Collection<DocumentAssignment>, 'latest_acted_at' => ...].
--}}
@php
    $badgeFor = function ($assignment) {
        return match(true) {
            $assignment->individual_status === 'rejected' => ['bg-rejected-50 text-rejected-700', 'Rejected'],
            $assignment->auto_approved => ['bg-processing-50 text-processing-700', 'Auto-Approved'],
            default => ['bg-approved-50 text-approved-700', 'Approved'],
        };
    };
    // Who ACTUALLY decided this seat — not always the approver it's
    // assigned to. An admin can decide on their behalf (Workflow Config,
    // via admin_override_by) without ever
    // reassigning the seat itself, and a rejection cascade can close a
    // sibling seat without its holder ever deciding anything (via
    // cascade_closed_by) — both need to be surfaced here, or this page
    // misrepresents whose call it really was. auto_approved already
    // states plainly it was the system, so no attribution needed there.
    //
    // Beyond those cases, THIS approver genuinely made the call
    // themselves — but a plain "by You" doesn't distinguish a joint
    // decision from a solo one. A rejection is always individually
    // decisive regardless of how many seats the stage had (it kills the
    // whole document on its own), so it's always attributed by name. An
    // approval on a multi-seat stage names EVERY approver who held a seat
    // on it (every one of them had to agree, not just this one) rather
    // than crediting a single person or staying anonymous.
    $namesForStage = function ($assignment) {
        return $assignment->document->assignments
            ->where('stage_id', $assignment->stage_id)
            ->pluck('approver.full_name')
            ->filter()
            ->unique()
            ->values();
    };
    $joinNames = function ($names) {
        if ($names->count() <= 1) {
            return $names->first();
        }
        return $names->slice(0, -1)->implode(', ') . ' and ' . $names->last();
    };
    // A rejection cascade can close THIS SAME approver's own other pending
    // seat on the same document (their own reject on stage 1 auto-closes
    // their own still-pending stage 2) — cascade_closed_by is then their
    // own user_id, not another approver's. That's still just "you
    // rejected it", not a separate fact worth its own attribution
    // branch/badge — only a cascade closed BY SOMEONE ELSE needs calling
    // out, since that's the case where the seat holder never actually
    // decided anything themselves.
    $isCascadeByOther = function ($assignment) {
        return $assignment->cascade_closed_by && (int) $assignment->cascade_closed_by !== (int) auth()->user()->user_id;
    };
    $attributionFor = function ($assignment) use ($namesForStage, $joinNames, $isCascadeByOther) {
        if ($assignment->auto_approved) {
            return null;
        }
        if ($assignment->admin_override_by) {
            return 'Admin (' . ($assignment->adminOverrideBy->full_name ?? 'Unknown') . ')';
        }
        if ($assignment->individual_status === 'rejected' && $isCascadeByOther($assignment)) {
            return $assignment->cascadeClosedBy->full_name ?? 'another approver';
        }
        if ($assignment->individual_status === 'rejected') {
            return auth()->user()->full_name;
        }

        return $joinNames($namesForStage($assignment));
    };
    // Grouping key for the SUMMARY row specifically (the collapsed badges
    // before expanding a document) — deliberately coarser than the
    // per-stage truth above: every self-decided approval on a document
    // collapses into ONE badge regardless of which stage(s) it came from
    // or how many seats each had, since seeing e.g. "Approved — by
    // Lessur Vinz" and "Approved — by Lessur Vinz and Vinz Lessur" side
    // by side on the same row reads as a duplicate rather than two
    // distinct facts. The expanded per-stage panel below still shows the
    // exact accurate breakdown per stage via $attributionFor.
    $attributionKeyFor = function ($assignment) use ($isCascadeByOther) {
        if ($assignment->auto_approved) {
            return 'auto';
        }
        if ($assignment->admin_override_by) {
            return 'admin:' . $assignment->admin_override_by;
        }
        if ($assignment->individual_status === 'rejected' && $isCascadeByOther($assignment)) {
            return 'cascade:' . $assignment->cascade_closed_by;
        }
        if ($assignment->individual_status === 'rejected') {
            return 'you';
        }
        return 'approved-self';
    };
    // Attribution text for a merged SUMMARY group — a group can now span
    // more than one stage (see $attributionKeyFor above), so this unions
    // every participant name across every item in the group instead of
    // just reading the first one's stage.
    $summaryAttributionFor = function ($group) use ($namesForStage, $joinNames, $isCascadeByOther) {
        $first = $group->first();
        if ($first->auto_approved) {
            return null;
        }
        if ($first->admin_override_by) {
            return 'Admin (' . ($first->adminOverrideBy->full_name ?? 'Unknown') . ')';
        }
        if ($first->individual_status === 'rejected' && $isCascadeByOther($first)) {
            return $first->cascadeClosedBy->full_name ?? 'another approver';
        }
        if ($first->individual_status === 'rejected') {
            return auth()->user()->full_name;
        }

        $allNames = $group->flatMap($namesForStage)->unique()->values();
        return $joinNames($allNames);
    };
@endphp
<div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-surface-200 flex items-center justify-between">
        <h2 class="text-sm font-semibold text-surface-900">Decision History</h2>
        <span class="text-xs text-surface-400">{{ $decisions->total() }} document{{ $decisions->total() === 1 ? '' : 's' }}</span>
    </div>
    <div class="overflow-x-auto">
    <table class="w-full min-w-[720px] text-sm">
        <thead class="bg-surface-50 text-surface-500 text-xs uppercase tracking-wide">
            <tr>
                <th class="text-left px-6 py-3 font-medium">Document</th>
                <th class="text-left px-6 py-3 font-medium">Category</th>
                <th class="text-left px-6 py-3 font-medium">Decisions</th>
                <th class="text-left px-6 py-3 font-medium">Last Decided</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-surface-100">
            @forelse($decisions as $container)
                @php $doc = $container->document; @endphp
                <tr class="hover:bg-surface-50 transition-colors {{ $doc ? 'cursor-pointer' : '' }}"
                    @if($doc) onclick="toggleHistoryMovements({{ $doc->document_id }}, this)" @endif>
                    <td class="px-6 py-3 font-medium text-surface-800 max-w-xs truncate">
                        @if($doc)
                            <svg class="history-expand-icon inline-block w-3 h-3 mr-1 text-surface-400 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                        @endif
                        {{ $doc->title ?? 'Deleted document' }}
                    </td>
                    <td class="px-6 py-3 text-surface-600">{{ $doc->ml_category ?? '—' }}</td>
                    <td class="px-6 py-3">
                        @php
                            // Collapse identical outcome+attribution combos
                            // into one badge — several stages on the same
                            // document, all decided the same way by the
                            // same actor, previously repeated the exact
                            // same badge back to back for no reason.
                            $summaryGroups = $container->decisions
                                ->groupBy(fn ($a) => $a->individual_status . '|' . $attributionKeyFor($a));
                        @endphp
                        <div class="flex flex-wrap gap-1.5">
                            @foreach($summaryGroups as $group)
                                @php
                                    $first = $group->first();
                                    [$badgeClass, $badgeLabel] = $badgeFor($first);
                                    $stageNames = $group->pluck('stage.stage_name')->filter()->implode(', ');
                                    $attribution = $summaryAttributionFor($group);
                                @endphp
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold {{ $badgeClass }}" title="{{ $stageNames }}">
                                    {{ $badgeLabel }}
                                    @if($attribution)
                                        — by {{ $attribution }}
                                    @endif
                                </span>
                            @endforeach
                        </div>
                    </td>
                    <td class="px-6 py-3 text-surface-500 whitespace-nowrap">{{ $container->latest_acted_at?->format('M j, Y g:i A') ?? '—' }}</td>
                </tr>
                @if($doc)
                    <tr id="history-movements-{{ $doc->document_id }}" class="hidden bg-surface-50/60">
                        <td colspan="4" class="px-6 py-3">
                            <div class="border border-surface-200 rounded-lg overflow-hidden bg-white flex flex-col">
                                <div class="px-4 py-2 border-b border-surface-100 flex items-center justify-between">
                                    <span class="text-xs font-medium text-surface-500">Your decisions on this document</span>
                                    <button type="button" onclick="event.stopPropagation(); openDocumentViewer('{{ route('documents.file', $doc) }}', '{{ $doc->mime_type }}', '{{ addslashes($doc->original_filename ?? $doc->title) }}', {{ $doc->document_id }})"
                                        class="text-xs font-medium text-primary-700 hover:underline">View original file &rarr;</button>
                                </div>
                                <ul class="divide-y divide-surface-100">
                                    @foreach($container->decisions as $assignment)
                                        @php
                                            [$badgeClass, $badgeLabel] = $badgeFor($assignment);
                                            $attribution = $attributionFor($assignment);
                                        @endphp
                                        <li class="px-4 py-2.5 text-xs">
                                            <div class="flex items-center justify-between gap-3">
                                                <span class="font-medium text-surface-700">{{ $assignment->stage->stage_name ?? '—' }}</span>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full font-semibold {{ $badgeClass }}">
                                                    {{ $badgeLabel }}
                                                    @if($attribution)
                                                        — by {{ $attribution }}
                                                    @endif
                                                </span>
                                            </div>
                                            <p class="text-surface-500 mt-1">{{ $assignment->acted_at?->format('M j, Y g:i A') ?? '—' }}</p>
                                            @if($assignment->comments)
                                                <div class="mt-1.5 rounded-md bg-surface-50 border border-surface-100 px-2.5 py-1.5">
                                                    <span class="font-semibold text-surface-600">{{ $assignment->individual_status === 'rejected' ? 'Rejected Due To:' : 'Comments:' }}</span>
                                                    <span class="text-surface-600">{{ $assignment->comments }}</span>
                                                </div>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                                {{-- Opens the shared Document Tracker popup
                                     instead of expanding the full table
                                     inline right here — see admin/partials/
                                     audit-row.blade.php's matching comment
                                     for why a popup. This "Your decisions"
                                     section above stays inline either way,
                                     since it's approver-specific, not part
                                     of the general Document Tracker. --}}
                                <div class="px-4 py-2.5 border-t border-surface-100">
                                    <button type="button" onclick="event.stopPropagation(); openKpiDrilldown('document-tracker', '{{ addslashes($doc->title) }}', '{{ route('documents.trackerModal', $doc) }}')"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-primary-50 hover:bg-primary-100 text-primary-700 text-xs font-medium transition-colors">
                                        View Document Tracker &rarr;
                                    </button>
                                </div>
                            </div>
                        </td>
                    </tr>
                @endif
            @empty
                <tr>
                    <td colspan="4" class="px-6 py-10 text-center text-surface-400 text-sm">No decisions match your search.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
    </div>
    @if($decisions->hasPages())
        <div class="px-6 py-4 border-t border-surface-200">{{ $decisions->links() }}</div>
    @endif
</div>
