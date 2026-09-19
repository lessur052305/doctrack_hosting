{{--
    "Select Approver(s)" — the originator-directed routing follow-up step
    (Feature: bypass the standard pipeline — see WorkflowService::
    routeToCustomApprovers()). Fetched by the shared openKpiDrilldown()
    modal (see components/kpi-drilldown-modal.blade.php) — same pattern
    already used for the Approver Queue's "Review & Comment" panel, so no
    new modal component needed.

    No delegated JS required here, unlike that other panel — this is just
    a plain <form method="POST">, which submits and redirects normally
    regardless of how the DOM was inserted (unlike a <script> tag, a form
    doesn't need JS to work when injected via body.innerHTML).

    Exactly one of $stageGroups / $groupedApprovers is set (see
    DocumentController::selectApprovers()): $stageGroups for a known-
    category ('custom') document, grouped Category -> Stage -> Approver;
    $groupedApprovers for an 'unrelated' one, grouped by department only
    (there's no category here to organize stages under).
--}}
<div class="p-6">
    <h2 class="text-base font-semibold text-surface-900 mb-1">{{ $document->title }}</h2>

    @if($document->desired_routing === 'unrelated')
        <p class="text-sm text-surface-500 mb-1">
            Marked as not belonging to any of the standard categories
            @if($document->ml_category)
                (system's best guess: <span class="font-medium text-surface-700">{{ $document->ml_category }}</span>, for reference only)
            @endif
            — choose who should review it.
        </p>
    @else
        <p class="text-sm text-surface-500 mb-1">
            Classified as <span class="font-medium text-surface-700">{{ $document->ml_category }}</span> — choose who should review it instead of the standard pipeline.
        </p>
    @endif
    <p class="text-xs text-surface-400 mb-5">This becomes the entire approval process for this document — approval needs every one you pick, a rejection needs a majority.</p>

    @php
        $isEmpty = $stageGroups ? $stageGroups->isEmpty() : $groupedApprovers->isEmpty();
    @endphp

    @if($isEmpty)
        <p class="text-sm text-rejected-700 bg-rejected-50 border border-rejected-500/20 rounded-lg px-4 py-3">
            No active approvers are currently eligible to pick from. Contact an Administrator.
        </p>
    @else
        <form method="POST" action="{{ route('originator.documents.routeCustom', $document) }}" class="space-y-4">
            @csrf
            <div class="space-y-5 max-h-96 overflow-y-auto pr-1">
                @if($stageGroups)
                    {{-- Category -> Stage -> Approver (Feature: see exactly who
                         covers which stage, not just a flat name list). The
                         category heading is deliberately much more prominent
                         than a plain section label — it's the one piece of
                         context that applies to every stage/approver below it. --}}
                    <p class="text-lg font-bold text-primary-800 pb-1.5 border-b-2 border-primary-100">{{ $document->ml_category }}</p>
                    @foreach($stageGroups as $group)
                        <div>
                            <p class="text-sm font-semibold text-surface-800 mb-1.5">{{ $group->stage->stage_name }}</p>
                            <ul class="space-y-2">
                                @foreach($group->approvers as $approver)
                                    <li class="rounded-lg border border-surface-200 p-3 hover:bg-surface-50">
                                        <label class="flex items-start gap-2 cursor-pointer">
                                            <input type="checkbox" name="approver_ids[]" value="{{ $approver->user_id }}" class="mt-1 rounded border-surface-300">
                                            <span class="min-w-0">
                                                <span class="flex items-center gap-1.5 flex-wrap">
                                                    <span class="text-sm font-medium text-surface-800">{{ $approver->full_name }}</span>
                                                    @if($approver->level === 'head')
                                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-indigo-100 text-indigo-700">Head</span>
                                                    @elseif($approver->level === 'staff')
                                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-surface-100 text-surface-600">Staff</span>
                                                    @endif
                                                    {{-- Department as its own colored badge, not a faint
                                                         caption underneath — easy to miss at a glance
                                                         otherwise, and it's often the deciding factor for
                                                         who to pick. --}}
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-primary-50 text-primary-700">{{ $approver->department ?: 'No Department' }}</span>
                                                </span>
                                            </span>
                                        </label>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                @else
                    {{-- Grouped by department, head(s) listed first within each
                         (see DocumentController::groupApproversByDepartment()) —
                         a document that "just needs the head's sign-off" is
                         exactly the case this grouping exists to make obvious
                         at a glance, instead of hunting through one flat
                         alphabetical list. Department is already the section
                         heading here, so it doesn't need its own badge too. --}}
                    @foreach($groupedApprovers as $department => $departmentApprovers)
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-surface-400 mb-1.5">{{ $department }}</p>
                            <ul class="space-y-2">
                                @foreach($departmentApprovers as $approver)
                                    <li class="rounded-lg border border-surface-200 p-3 hover:bg-surface-50">
                                        <label class="flex items-start gap-2 cursor-pointer">
                                            <input type="checkbox" name="approver_ids[]" value="{{ $approver->user_id }}" class="mt-1 rounded border-surface-300">
                                            <span class="min-w-0">
                                                <span class="flex items-center gap-1.5">
                                                    <span class="text-sm font-medium text-surface-800">{{ $approver->full_name }}</span>
                                                    @if($approver->level === 'head')
                                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-indigo-100 text-indigo-700">Head</span>
                                                    @elseif($approver->level === 'staff')
                                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-surface-100 text-surface-600">Staff</span>
                                                    @endif
                                                </span>
                                                {{-- Which stage(s) they're actually tied to — see
                                                     DocumentController::stagesLabelFor(). --}}
                                                <span class="block text-xs text-surface-400 mt-0.5">{{ $approver->stages_label }}</span>
                                            </span>
                                        </label>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                @endif
            </div>
            <button type="submit" class="w-full bg-primary-700 hover:bg-primary-800 text-white text-sm font-semibold py-2.5 rounded-lg">Route to Selected Approver(s)</button>
        </form>
    @endif
</div>
