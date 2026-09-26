{{--
    Approval Workflow stage list (read-only) — split out from
    workflow_config.blade.php so the same markup can be rendered two ways: a
    normal full page load, and a fragment returned by
    AdminController::workflowConfigRefresh() for the live-poll JS to swap in
    place, without a full page reload.
--}}
@foreach($stages as $category => $categoryStages)
    <div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
        <div class="px-6 py-3 border-b border-surface-200 bg-surface-50">
            <h3 class="text-sm font-semibold text-surface-900">{{ $category }}</h3>
        </div>
        <ul class="divide-y divide-surface-100 text-sm">
            @foreach($categoryStages as $s)
                @php
                    $pendingSeats = $pendingByStage[$s->stage_id] ?? collect();
                    $isFinal = $s->stage_name === 'Final Approval';
                    $departmentNames = $s->departmentNames();
                @endphp
                <li class="px-6 py-4 {{ $s->is_archived ? 'opacity-60' : '' }}">
                    <div class="min-w-0">
                        <span class="font-medium text-surface-800">{{ $s->sequence_order }}. {{ $s->stage_name }}</span>
                        @if($isFinal)
                            <span class="ml-2 text-[10px] font-semibold px-2 py-0.5 rounded-full bg-primary-50 text-primary-700 uppercase tracking-wide">Head Approver sign-off</span>
                        @endif
                        @if($s->is_archived)
                            <span class="ml-2 text-[10px] font-semibold px-2 py-0.5 rounded-full bg-surface-200 text-surface-600 uppercase tracking-wide">Archived</span>
                        @endif
                        <p class="text-xs text-surface-400">{{ $s->description }}</p>

                        <p class="text-xs text-surface-500 mt-1">
                            Owned by:
                            @forelse($departmentNames as $department)
                                <span class="inline-block px-2 py-0.5 rounded-full bg-surface-100 text-surface-700 font-medium">{{ $department }}</span>
                            @empty
                                <span class="text-surface-400">any department</span>
                            @endforelse
                            @if($isFinal)
                                <span class="text-surface-400">— opens after every other stage is approved</span>
                            @endif
                        </p>

                        @if($pendingSeats->isNotEmpty())
                            <details class="mt-2 text-xs">
                                <summary class="cursor-pointer text-processing-700 hover:underline font-medium">{{ $pendingSeats->count() }} pending assignment(s)</summary>
                                <ul class="mt-2 divide-y divide-surface-100 border border-surface-200 rounded-lg overflow-hidden">
                                    @foreach($pendingSeats as $assignment)
                                        @php $pDoc = $assignment->document; @endphp
                                        <li class="px-3 py-2 bg-white flex flex-wrap items-center justify-between gap-2">
                                            <div class="min-w-0">
                                                <span class="text-surface-700 font-medium truncate block">{{ $pDoc->title ?? 'Deleted document' }}</span>
                                                <span class="text-[11px] text-surface-400">
                                                    {{ $assignment->approver->full_name ?? 'Unassigned' }}
                                                    @if($assignment->sla_expires_at) — respond by {{ $assignment->sla_expires_at->format('M j, g:i A') }} @endif
                                                </span>
                                            </div>
                                            @if($pDoc)
                                                <button type="button"
                                                    onclick="openDocumentViewer('{{ route('documents.file', $pDoc) }}', '{{ $pDoc->mime_type }}', '{{ addslashes($pDoc->original_filename ?? $pDoc->title) }}')"
                                                    class="text-[11px] text-primary-700 hover:underline font-medium">
                                                    View original file
                                                </button>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
@endforeach
