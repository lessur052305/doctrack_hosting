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
                <p class="text-[11px] text-surface-400">Approver SLA windows are no longer configured per stage — they're calculated automatically as 25% of the time remaining until each document's own due date.</p>
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
            <div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
                <div class="px-6 py-3 border-b border-surface-200 bg-surface-50">
                    <h3 class="text-sm font-semibold text-surface-900">{{ $category }}</h3>
                </div>
                <ul class="divide-y divide-surface-100 text-sm">
                    @foreach($categoryStages as $s)
                        <li class="px-6 py-3 flex justify-between items-center">
                            <div>
                                <span class="font-medium text-surface-800">{{ $s->sequence_order }}. {{ $s->stage_name }}</span>
                                <p class="text-xs text-surface-400">{{ $s->description }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
</div>
@endsection 