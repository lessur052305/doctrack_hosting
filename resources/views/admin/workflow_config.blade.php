@extends('layouts.app')
@section('title', 'Workflow Config')
@section('page-title', 'Workflow Configuration')

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-6">
            <h2 class="text-sm font-semibold text-surface-900 mb-4">Add Workflow Stage</h2>
            <form method="POST" action="{{ route('admin.workflow.store') }}" class="space-y-3">
                @csrf
                <div>
                    <label class="block text-xs font-medium text-surface-700 mb-1">Document Category</label>
                    <select name="document_category" required class="w-full rounded-lg border-surface-300 text-sm px-3 py-2">
                        @foreach($categories as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-surface-700 mb-1">Stage Name</label>
                    <input name="stage_name" required class="w-full rounded-lg border-surface-300 text-sm px-3 py-2">
                </div>
                <div>
                    <label class="block text-xs font-medium text-surface-700 mb-1">Sequence Order</label>
                    <input type="number" name="sequence_order" min="1" required class="w-full rounded-lg border-surface-300 text-sm px-3 py-2">
                </div>
                <p class="text-[11px] text-surface-400">Approver SLA windows are no longer configured per stage — they're calculated automatically as a business-hours-aware percentage of the time remaining until each document's own due date.</p>
                <div>
                    <label class="block text-xs font-medium text-surface-700 mb-1">Description</label>
                    <textarea name="description" rows="2" class="w-full rounded-lg border-surface-300 text-sm px-3 py-2"></textarea>
                </div>
                <button class="w-full bg-primary-700 hover:bg-primary-800 text-white text-sm font-medium py-2.5 rounded-lg">Add Stage</button>
            </form>
        </div>
    </div>

    <div class="lg:col-span-2 space-y-6">
        @foreach($stages as $category => $categoryStages)
            @php
                $activeStageIds = $categoryStages->where('is_archived', false)->pluck('stage_id');
            @endphp
            <div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
                <div class="px-6 py-3 border-b border-surface-200 bg-surface-50">
                    <h3 class="text-sm font-semibold text-surface-900">{{ $category }}</h3>
                </div>
                <ul class="divide-y divide-surface-100 text-sm">
                    @foreach($categoryStages as $s)
                        @php
                            $pendingCount = $activeCounts[$s->stage_id] ?? 0;
                            $historyCount = $historyCounts[$s->stage_id] ?? 0;
                            $siblingStages = $activeStageIds->reject(fn ($id) => $id === $s->stage_id);
                        @endphp
                        <li class="px-6 py-4 {{ $s->is_archived ? 'opacity-60' : '' }}">
                            <div class="flex justify-between items-start gap-3">
                                <div class="min-w-0">
                                    <span class="font-medium text-surface-800">{{ $s->sequence_order }}. {{ $s->stage_name }}</span>
                                    @if($s->is_archived)
                                        <span class="ml-2 text-[10px] font-semibold px-2 py-0.5 rounded-full bg-surface-200 text-surface-600 uppercase tracking-wide">Archived</span>
                                    @endif
                                    <p class="text-xs text-surface-400">{{ $s->description }}</p>
                                    @if($pendingCount > 0)
                                        <p class="text-xs text-rejected-700 mt-1">{{ $pendingCount }} active assignment(s) pending on this stage.</p>
                                    @endif
                                </div>

                                @unless($s->is_archived)
                                <div class="flex items-center gap-1 shrink-0">
                                    <form method="POST" action="{{ route('admin.workflow.stages.moveUp', $s) }}">
                                        @csrf
                                        <button class="w-7 h-7 rounded-lg border border-surface-200 hover:bg-surface-50 text-surface-500" title="Move up">&uarr;</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.workflow.stages.moveDown', $s) }}">
                                        @csrf
                                        <button class="w-7 h-7 rounded-lg border border-surface-200 hover:bg-surface-50 text-surface-500" title="Move down">&darr;</button>
                                    </form>
                                </div>
                                @endunless
                            </div>

                            @unless($s->is_archived)
                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                <details class="text-xs">
                                    <summary class="cursor-pointer text-primary-700 hover:underline font-medium">Edit</summary>
                                    <form method="POST" action="{{ route('admin.workflow.stages.update', $s) }}" class="mt-2 space-y-2 max-w-sm">
                                        @csrf
                                        @method('PUT')
                                        <input name="stage_name" value="{{ $s->stage_name }}" required class="w-full rounded-lg border-surface-300 text-xs px-3 py-2">
                                        <textarea name="description" rows="2" class="w-full rounded-lg border-surface-300 text-xs px-3 py-2">{{ $s->description }}</textarea>
                                        <button class="text-xs font-medium bg-primary-700 hover:bg-primary-800 text-white px-3 py-1.5 rounded-lg">Save</button>
                                    </form>
                                </details>

                                @if($pendingCount > 0)
                                    <details class="text-xs">
                                        <summary class="cursor-pointer text-processing-700 hover:underline font-medium">Reassign pending &amp; then remove</summary>
                                        <form method="POST" action="{{ route('admin.workflow.stages.reassign', $s) }}" class="mt-2 flex items-center gap-2">
                                            @csrf
                                            <select name="target_stage_id" required class="rounded-lg border-surface-300 text-xs px-3 py-2">
                                                @foreach($siblingStages as $siblingId)
                                                    @php $sibling = $categoryStages->firstWhere('stage_id', $siblingId); @endphp
                                                    <option value="{{ $siblingId }}">{{ $sibling->stage_name }}</option>
                                                @endforeach
                                            </select>
                                            <button class="text-xs font-medium bg-processing-700 hover:bg-processing-800 text-white px-3 py-1.5 rounded-lg">Reassign {{ $pendingCount }}</button>
                                        </form>
                                    </details>
                                @else
                                    <form method="POST" action="{{ route('admin.workflow.stages.archive', $s) }}">
                                        @csrf
                                        <button class="text-xs font-medium text-surface-500 hover:underline">Archive</button>
                                    </form>

                                    @if($historyCount === 0)
                                        <form method="POST" action="{{ route('admin.workflow.stages.destroy', $s) }}" onsubmit="return confirm('Permanently delete this never-used stage?');">
                                            @csrf
                                            @method('DELETE')
                                            <button class="text-xs font-medium text-rejected-700 hover:underline">Delete</button>
                                        </form>
                                    @else
                                        <span class="text-xs text-surface-300" title="Has assignment history — archive instead of deleting.">Delete unavailable (has history)</span>
                                    @endif
                                @endif
                            </div>
                            @else
                                <form method="POST" action="{{ route('admin.workflow.stages.unarchive', $s) }}" class="mt-2">
                                    @csrf
                                    <button class="text-xs font-medium text-primary-700 hover:underline">Unarchive</button>
                                </form>
                            @endunless
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
</div>
@endsection
