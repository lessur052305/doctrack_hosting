{{--
    Fragment refreshed live by ml_training.blade.php's #ml-metrics-panels
    container (Reverb channel + poll fallback, see that file's <script>) —
    keep this partial self-contained (no reliance on anything outside the
    variables AdminController::mlTraining()/mlMetricsRefresh() pass it).
--}}
<div class="grid grid-cols-1 gap-6">

    {{-- Document Classifier: metrics + active model + history, one bound-together card --}}
    <div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
        @if($activeModel)
            <div class="px-5 py-4 border-b border-surface-100">
                <h3 class="text-xs font-semibold text-surface-900 uppercase tracking-wide mb-3">How Accuracy Is Measured</h3>
                <div class="grid grid-cols-3 gap-3">
                    <div class="bg-surface-50 rounded-lg p-3 text-center">
                        <p class="text-xl font-bold text-surface-900">{{ $activeModel->cv_folds ?? '—' }}</p>
                        <p class="text-xs font-semibold text-surface-800 mt-0.5">Practice Quizzes</p>
                        <p class="text-[11px] text-surface-400 mt-0.5 leading-snug">Tested {{ $activeModel->cv_folds ?? 'several' }} separate times, not just once.</p>
                    </div>
                    <div class="bg-surface-50 rounded-lg p-3 text-center">
                        <p class="text-xl font-bold text-surface-900">100%</p>
                        <p class="text-xs font-semibold text-surface-800 mt-0.5">Fresh Questions Each Time</p>
                        <p class="text-[11px] text-surface-400 mt-0.5 leading-snug">Every quiz uses documents it wasn't shown while studying for that round.</p>
                    </div>
                    <div class="bg-surface-50 rounded-lg p-3 text-center">
                        <p class="text-xl font-bold text-surface-900">1</p>
                        <p class="text-xs font-semibold text-surface-800 mt-0.5">Combined Score</p>
                        <p class="text-[11px] text-surface-400 mt-0.5 leading-snug">All quiz results are averaged into the one Accuracy % below.</p>
                    </div>
                </div>
            </div>
        @endif

        <div class="px-5 py-4 border-b border-surface-100">
            <h3 class="text-xs font-semibold text-surface-900 uppercase tracking-wide mb-2">Active Model</h3>
            @if($activeModel)
                <dl class="space-y-1.5 text-sm">
                    <div class="flex justify-between"><dt class="text-surface-500">Version</dt><dd class="font-medium">{{ $activeModel->version }}</dd></div>
                    <div class="flex justify-between"><dt class="text-surface-500">Samples</dt><dd class="font-medium">{{ $activeModel->training_sample_count }}</dd></div>
                    <div class="flex justify-between"><dt class="text-surface-500">Accuracy</dt><dd class="font-medium text-approved-700">{{ $activeModel->accuracy_score }}%</dd></div>
                </dl>
            @else
                <p class="text-sm text-surface-400">No trained model yet — upload samples to get started.</p>
            @endif
        </div>

        <div>
            <h3 class="px-5 pt-3 pb-2 text-xs font-semibold text-surface-900 uppercase tracking-wide">Training History</h3>
            <ul class="divide-y divide-surface-100 text-sm max-h-40 overflow-y-auto">
                @forelse($history as $m)
                    <li class="px-5 py-2.5 flex justify-between items-center">
                        <div>
                            <p class="font-medium text-surface-800">{{ $m->version }}</p>
                            <p class="text-xs text-surface-400">{{ $m->last_trained?->format('M j, Y g:i A') }}</p>
                        </div>
                        <span class="text-xs font-semibold {{ $m->is_active ? 'text-approved-700' : 'text-surface-400' }}">
                            {{ $m->is_active ? 'Active' : $m->accuracy_score . '%' }}
                        </span>
                    </li>
                @empty
                    <li class="px-5 py-4 text-center text-xs text-surface-400">No training history yet.</li>
                @endforelse
            </ul>
        </div>
    </div>

    {{--
        Estimated Approval Time models — read-only, no "train now"
        control here on purpose (see ApprovalTimeMlService's docblock):
        this one trains itself automatically on a schedule once a
        category/department combo has enough real decision history.
    --}}
    <div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-surface-100">
            <h3 class="text-xs font-semibold text-surface-900 uppercase tracking-wide mb-3">How This Is Calculated</h3>
            <div class="grid grid-cols-3 gap-3">
                <div class="bg-surface-50 rounded-lg p-3 text-center">
                    <p class="text-xl font-bold text-surface-900">2</p>
                    <p class="text-xs font-semibold text-surface-800 mt-0.5">What It Looks At</p>
                    <p class="text-[11px] text-surface-400 mt-0.5 leading-snug">Just 2 things: the approver's own average speed, and the day of the week.</p>
                </div>
                <div class="bg-surface-50 rounded-lg p-3 text-center">
                    <p class="text-xl font-bold text-surface-900">{{ $timeEstimateTrainingFloor }}+</p>
                    <p class="text-xs font-semibold text-surface-800 mt-0.5">Real Cases Needed First</p>
                    <p class="text-[11px] text-surface-400 mt-0.5 leading-snug">Won't estimate for a category/department until it has seen at least this many real decisions.</p>
                </div>
                <div class="bg-surface-50 rounded-lg p-3 text-center">
                    <p class="text-xl font-bold text-surface-900">Cautious</p>
                    <p class="text-xs font-semibold text-surface-800 mt-0.5">Careful With Little Data</p>
                    <p class="text-[11px] text-surface-400 mt-0.5 leading-snug">Plays it safe early on, trusts the data more as real decisions add up.</p>
                </div>
            </div>
        </div>

        <div>
            <h3 class="px-5 pt-3 pb-2 text-xs font-semibold text-surface-900 uppercase tracking-wide">Estimated Approval Time</h3>
            <ul class="divide-y divide-surface-100 text-sm max-h-40 overflow-y-auto">
                @forelse($timeEstimateGroups as $group)
                    <li class="px-5 py-2.5">
                        <div class="flex justify-between items-center">
                            <p class="font-medium text-surface-800">{{ $group['ml_category'] }} &middot; {{ $group['department'] }}</p>
                            @if($group['model'])
                                <span class="text-xs font-semibold text-approved-700">{{ $group['model']->version }}</span>
                            @else
                                <span class="text-xs font-semibold text-surface-400">{{ $group['sample_count'] }}/{{ $timeEstimateTrainingFloor }}</span>
                            @endif
                        </div>
                        <p class="text-xs text-surface-400 mt-0.5">
                            @if($group['model'])
                                Off by ~{{ \Carbon\CarbonInterval::seconds($group['model']->mae_seconds)->cascade()->forHumans(['short' => true]) }} on average &middot; {{ $group['model']->training_sample_count }} samples &middot; trained {{ $group['model']->trained_at->diffForHumans() }}
                            @else
                                Not trained yet — using the plain average estimate until enough history builds up.
                            @endif
                        </p>
                    </li>
                @empty
                    <li class="px-5 py-6 text-center text-xs text-surface-400">No real decision history yet.</li>
                @endforelse
            </ul>
        </div>
    </div>

</div>
