@extends('layouts.app')
@section('title', 'ML Training')
@section('page-title', 'Machine Learning Training')

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-6">
            <h2 class="text-sm font-semibold text-surface-900 mb-1">Train / Retrain Classifier</h2>
            <p class="text-xs text-surface-500 mb-6">Upload <strong>5–10 sample documents per category</strong>. The system extracts text (with OCR fallback), computes TF-IDF features, and rebuilds the classification model.</p>

            <form method="POST" action="{{ route('admin.ml.train') }}" enctype="multipart/form-data" class="space-y-6">
                @csrf
                @foreach($categories as $category)
                    @php $key = 'samples_' . str_replace(' ', '_', strtolower($category)); @endphp
                    <div class="border border-surface-200 rounded-lg p-4">
                        <div class="flex items-center justify-between mb-2">
                            <label class="text-sm font-medium text-surface-800">{{ $category }}</label>
                            <span class="text-xs text-surface-400">5–10 files required</span>
                        </div>
                        <input type="file" name="{{ $key }}[]" multiple required
                            accept=".pdf,.txt,.docx"
                            class="w-full text-xs text-surface-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100">
                    </div>
                @endforeach

                <button type="submit"
                    class="w-full bg-primary-700 hover:bg-primary-800 text-white text-sm font-medium py-2.5 rounded-lg transition-colors">
                    Train Model
                </button>
            </form>
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
