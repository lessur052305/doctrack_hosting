@extends('layouts.app')
@section('title', 'ML Training')
@section('page-title', 'Machine Learning Training')

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-6">
            <h2 class="text-sm font-semibold text-surface-900 mb-1">Train / Retrain Classifier</h2>
            <p class="text-xs text-surface-500 mb-6">
                <strong>Step 1:</strong> for each category below, pick 5–10 sample files, then click that category's own "Add" button — do this once per category.
                <strong>Step 2:</strong> once every category shows a green "staged" count, click "Train Model" at the bottom. Selecting files alone does not stage them — you must click each category's "Add" button first.
                Staged samples are shared across every admin account and stay in place until trained or cleared — no need to finish in one sitting.
            </p>

            @php
                $incomplete = collect($categories)->filter(fn ($c) => ($stagedSamples->get($c, collect()))->count() < 5)->values();
            @endphp

            <div class="space-y-6">
                @foreach($categories as $category)
                    @php
                        $samplesInCategory = $stagedSamples->get($category, collect());
                        $count = $samplesInCategory->count();
                        $remaining = 10 - $count;
                    @endphp
                    <div class="border rounded-lg p-4 {{ $count >= 5 ? 'border-approved-300 bg-approved-50/30' : 'border-surface-200' }}">
                        <div class="flex items-center justify-between mb-2">
                            <label class="text-sm font-medium text-surface-800">
                                {{ $count >= 5 ? '✓' : '' }} {{ $category }}
                            </label>
                            <span class="text-xs {{ $count >= 5 ? 'text-approved-700 font-medium' : 'text-surface-400' }}">
                                {{ $count }} of 10 staged{{ $count < 5 ? ' — need at least 5' : '' }}
                            </span>
                        </div>

                        @if($samplesInCategory->isNotEmpty())
                            <ul class="mb-3 divide-y divide-surface-100 border border-surface-100 rounded-lg overflow-hidden">
                                @foreach($samplesInCategory as $sample)
                                    <li class="flex items-center justify-between px-3 py-1.5 text-xs bg-surface-50/50">
                                        <span class="text-surface-600 truncate">
                                            {{ $sample->original_filename }}
                                            <span class="text-surface-400">— staged by {{ $sample->stagedBy->full_name ?? 'a former admin account' }}</span>
                                        </span>
                                        <form method="POST" action="{{ route('admin.ml.training.sample.destroy', $sample) }}">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="text-rejected-600 hover:underline flex-shrink-0 ml-2">Remove</button>
                                        </form>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @if($remaining > 0)
                            <form method="POST" action="{{ route('admin.ml.training.stage', $category) }}" enctype="multipart/form-data" class="flex items-center gap-2">
                                @csrf
                                <input type="file" name="files[]" multiple required
                                    accept=".pdf,.txt,.docx"
                                    class="flex-1 min-w-0 text-xs text-surface-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100">
                                <button type="submit"
                                    class="flex-shrink-0 text-xs font-medium bg-primary-700 hover:bg-primary-800 text-white px-3 py-2 rounded-lg">
                                    Add these files (up to {{ $remaining }})
                                </button>
                            </form>
                        @endif

                        @if($count > 0)
                            <form method="POST" action="{{ route('admin.ml.training.stage.clear', $category) }}" class="mt-2">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-xs text-rejected-600 hover:underline">Clear all staged samples for this category</button>
                            </form>
                        @endif
                    </div>
                @endforeach

                @if($incomplete->isNotEmpty())
                    <div class="rounded-lg bg-surface-100 border border-surface-200 px-4 py-3 text-xs text-surface-600">
                        Still need samples staged for: <strong>{{ $incomplete->implode(', ') }}</strong>. Remember to click each category's own "Add these files" button — the button below won't work until all three are ready.
                    </div>
                @endif

                <form method="POST" action="{{ route('admin.ml.train') }}">
                    @csrf
                    <button type="submit" {{ $incomplete->isNotEmpty() ? 'disabled' : '' }}
                        class="w-full text-white text-sm font-medium py-2.5 rounded-lg transition-colors {{ $incomplete->isNotEmpty() ? 'bg-surface-300 cursor-not-allowed' : 'bg-primary-700 hover:bg-primary-800' }}">
                        Train Model
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-6">
            <h2 class="text-sm font-semibold text-surface-900 mb-4">Current Active Model</h2>
            @if($activeModel)
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-surface-500">Version</dt><dd class="font-medium">{{ $activeModel->version }}</dd></div>
                    <div class="flex justify-between"><dt class="text-surface-500">Samples</dt><dd class="font-medium">{{ $activeModel->training_sample_count }}</dd></div>
                    <div class="flex justify-between"><dt class="text-surface-500">Accuracy</dt><dd class="font-medium text-approved-700">{{ $activeModel->accuracy_score }}%</dd></div>
                </dl>
            @else
                <p class="text-sm text-surface-400">No trained model yet — upload samples to get started.</p>
            @endif
        </div>

        <div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-surface-200"><h3 class="text-xs font-semibold text-surface-900 uppercase tracking-wide">Training History</h3></div>
            <ul class="divide-y divide-surface-100 text-sm">
                @foreach($history as $m)
                    <li class="px-5 py-3 flex justify-between items-center">
                        <div>
                            <p class="font-medium text-surface-800">{{ $m->version }}</p>
                            <p class="text-xs text-surface-400">{{ $m->last_trained?->format('M j, Y g:i A') }}</p>
                        </div>
                        <span class="text-xs font-semibold {{ $m->is_active ? 'text-approved-700' : 'text-surface-400' }}">
                            {{ $m->is_active ? 'Active' : $m->accuracy_score . '%' }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</div>
@endsection
