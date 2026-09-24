<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\MlModelRepository;
use App\Models\MlStagingSample;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\ClassificationService;

/**
 * Coverage for ClassificationService::autoTrainIfDue() — the fully-
 * automatic retraining that replaces the manual "Train Model" button for
 * every retrain after the one-time bootstrap. See that method's own
 * docblock for the two triggers (batch size / age) and the accuracy-
 * gated rollback.
 */
function distinctText(string $keyword, int $n): string
{
    return "{$keyword} reference number {$n}. This document concerns {$keyword} matters exclusively, "
        . "filed on a different date with different details each time, sample variant {$n}.";
}

/** Stages real, distinctly-worded curated samples for all 3 categories and trains a real bootstrap model. */
function bootstrapModel(int $perCategory = 5): MlModelRepository
{
    $keywords = [
        'Job Order' => 'aircon repair plumbing maintenance facilities work order',
        'Purchase Requisition' => 'laptop budget procurement requisition finance approval',
        'Service Report' => 'technician generator inspection findings completed service',
    ];

    foreach ($keywords as $category => $keyword) {
        for ($i = 1; $i <= $perCategory; $i++) {
            MlStagingSample::create([
                'category' => $category,
                'original_filename' => "seed-{$category}-{$i}.txt",
                'extracted_text' => distinctText($keyword, $i),
            ]);
        }
    }

    $samplesByCategory = MlStagingSample::get()->groupBy('category')
        ->map(fn ($rows) => $rows->pluck('extracted_text')->all())->all();

    return app(ClassificationService::class)->train($samplesByCategory);
}

/**
 * A confidently-classified, auto-trusted document eligible to teach the
 * model — matches WorkflowService::ingest()'s trusted tier. Also creates
 * the DocumentAssignment a real routed document would have —
 * autoTrainIfDue()'s eligibility query requires one (whereHas('assignments'))
 * specifically to exclude documents rejected below the confidence floor,
 * which never get routed at all; without a matching assignment row here,
 * these otherwise-legitimate test fixtures would be excluded the same way.
 */
function eligibleDoc(string $category, array $overrides = []): DocumentRepository
{
    $originator = User::factory()->originator()->create();

    $doc = DocumentRepository::create(array_merge([
        'originator_id' => $originator->user_id,
        'title' => 'eligible-' . uniqid() . '.txt',
        'file_path' => 'documents/' . uniqid() . '.txt',
        'mime_type' => 'text/plain',
        'ocr_text' => distinctText($category, random_int(1000, 9999)),
        'ml_category' => $category,
        'ml_confidence' => 90.0,
        'ml_margin' => 80.0,
        'is_validated' => true,
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
    ], $overrides));

    $stage = WorkflowStage::firstOrCreate(
        ['document_category' => $category, 'stage_name' => 'Review'],
        ['sequence_order' => 1]
    );

    DocumentAssignment::create([
        'document_id' => $doc->document_id,
        'user_id' => null,
        'stage_id' => $stage->stage_id,
        'due_date' => $doc->due_date,
        'priority_rank' => 2,
        'individual_status' => 'approved',
        'acted_at' => now(),
        'auto_approved' => true,
    ]);

    return $doc;
}

test('returns null when no model has ever been bootstrapped', function () {
    eligibleDoc('Job Order');

    expect(app(ClassificationService::class)->autoTrainIfDue())->toBeNull();
});

test('returns null when eligible documents exist but neither trigger is met', function () {
    bootstrapModel();
    // 2 waiting — below the default batch size (5) — and just trained, so
    // the age trigger (24h) hasn't elapsed either.
    eligibleDoc('Job Order');
    eligibleDoc('Job Order');

    expect(app(ClassificationService::class)->autoTrainIfDue())->toBeNull();
    expect(DocumentRepository::whereNotNull('used_for_training_at')->count())->toBe(0);
});

test('fires once the batch-size trigger is met', function () {
    config(['ml.auto_train_batch_size' => 3]);
    $original = bootstrapModel();

    $docs = collect([eligibleDoc('Job Order'), eligibleDoc('Job Order'), eligibleDoc('Job Order')]);

    $result = app(ClassificationService::class)->autoTrainIfDue();

    expect($result)->not->toBeNull()
        ->and($result['documentsUsed'])->toBe(3);

    $docs->each(fn ($d) => expect($d->fresh()->used_for_training_at)->not->toBeNull());

    // A genuinely new model version was created (whether kept or rolled
    // back — see the rollback test below for that distinction).
    expect(MlModelRepository::count())->toBeGreaterThan(1)
        ->and(MlModelRepository::find($original->model_id))->not->toBeNull();
});

test('fires once the age trigger is met, even with only 1 document waiting', function () {
    config(['ml.auto_train_max_age_hours' => 24]);
    $original = bootstrapModel();

    // Backdate the bootstrap model past the age threshold.
    $original->update(['last_trained' => now()->subHours(25)]);

    eligibleDoc('Job Order');

    $result = app(ClassificationService::class)->autoTrainIfDue();

    expect($result)->not->toBeNull()
        ->and($result['documentsUsed'])->toBe(1);
});

test('excludes documents the originator flagged unrelated, even at high confidence', function () {
    config(['ml.auto_train_batch_size' => 1]);
    bootstrapModel();

    eligibleDoc('Job Order', ['desired_routing' => 'unrelated']);

    expect(app(ClassificationService::class)->autoTrainIfDue())->toBeNull();
});

test('includes low-confidence documents in training too, and re-scores them once the retrain is kept', function () {
    // No confidence/margin floor on training eligibility anymore — a
    // document this unsure is exactly the kind that needs its real,
    // unfamiliar vocabulary folded into the next model. See
    // autoTrainIfDue()'s docblock on the eligibility query.
    config(['ml.auto_train_batch_size' => 1]);
    bootstrapModel();

    $doc = eligibleDoc('Job Order', ['ml_confidence' => 40.0, 'ml_margin' => 5.0]);

    $result = app(ClassificationService::class)->autoTrainIfDue();

    expect($result)->not->toBeNull()
        ->and($result['documentsUsed'])->toBe(1);

    $doc->refresh();
    expect($doc->ml_rechecked_at)->not->toBeNull()
        ->and($doc->ml_recheck_confidence)->not->toBeNull()
        ->and($doc->ml_recheck_category)->not->toBeNull()
        // Readability gets the identical recheck treatment, same batch,
        // same trigger — see ValidationService::categoryVocabulary()'s
        // widened source and autoTrainIfDue()'s recheck step.
        ->and($doc->ml_recheck_readability_score)->not->toBeNull();
});

test('respects the per-category population cap, oldest-eligible-first', function () {
    // 5 curated seed samples for Job Order, ratio 2.0 -> cap of 10 auto
    // documents included per run; the 11th and 12th stay un-marked,
    // eligible for a later run instead of being silently dropped.
    config(['ml.auto_train_batch_size' => 1, 'ml.auto_train_max_auto_ratio' => 2.0]);
    bootstrapModel(perCategory: 5);

    $docs = collect(range(1, 12))->map(function ($i) {
        $doc = eligibleDoc('Job Order');
        $doc->created_at = now()->subMinutes(100 - $i); // ascending order, oldest first
        $doc->save();

        return $doc;
    });

    app(ClassificationService::class)->autoTrainIfDue();

    $usedCount = $docs->filter(fn ($d) => $d->fresh()->used_for_training_at !== null)->count();
    expect($usedCount)->toBe(10);

    // The two NEWEST documents (last in the oldest-first ordering) are the
    // ones left over the cap.
    expect($docs->last()->fresh()->used_for_training_at)->toBeNull();
});

test('rolls back to the previous model when the new attempt scores worse, leaving the active model unchanged', function () {
    config(['ml.auto_train_batch_size' => 1]);
    $original = bootstrapModel();
    $originalAccuracy = $original->accuracy_score;

    eligibleDoc('Job Order');

    // andReturnUsing, not andReturn — the real train() deactivates every
    // previously-active model as part of registering the new one (see its
    // own "5. Register the new version" step), so the stub needs to
    // replicate that side effect too, not just hand back a model row.
    $mock = Mockery::mock(ClassificationService::class)->makePartial();
    $mock->shouldReceive('train')->once()->andReturnUsing(function () use ($original, $originalAccuracy) {
        MlModelRepository::where('is_active', true)->update(['is_active' => false]);

        return MlModelRepository::create([
            'model_name' => 'Support Vector Machine (SVM) + TF-IDF',
            'version' => 'v-test-worse',
            'accuracy_score' => max(0.0, $originalAccuracy - 50.0),
            'model_file_path' => $original->model_file_path,
            'training_sample_count' => 16,
            'is_active' => true,
            'last_trained' => now(),
        ]);
    });

    $result = $mock->autoTrainIfDue();

    expect($result)->not->toBeNull()
        ->and($result['kept'])->toBeFalse();

    expect($original->fresh()->is_active)->toBeTrue()
        ->and(MlModelRepository::active()->model_id)->toBe($original->model_id);
});

test('keeps the new model active when it scores at least as well as the previous one', function () {
    config(['ml.auto_train_batch_size' => 1]);
    $original = bootstrapModel();

    eligibleDoc('Job Order');

    $mock = Mockery::mock(ClassificationService::class)->makePartial();
    $mock->shouldReceive('train')->once()->andReturnUsing(function () use ($original) {
        MlModelRepository::where('is_active', true)->update(['is_active' => false]);

        return MlModelRepository::create([
            'model_name' => 'Support Vector Machine (SVM) + TF-IDF',
            'version' => 'v-test-better',
            'accuracy_score' => 100.0,
            'model_file_path' => $original->model_file_path,
            'training_sample_count' => 16,
            'is_active' => true,
            'last_trained' => now(),
        ]);
    });

    $result = $mock->autoTrainIfDue();

    expect($result)->not->toBeNull()
        ->and($result['kept'])->toBeTrue()
        ->and($original->fresh()->is_active)->toBeFalse();

    expect(MlModelRepository::active()->version)->toBe('v-test-better');
});

test('keeps a small drop within the rollback tolerance instead of discarding it', function () {
    // The exact scenario this tolerance exists for: previous model at
    // 100% (nowhere to go but down or flat), new attempt at 97% — a
    // small, expected dip from testing against a bigger/more varied
    // pool, not a real regression. Default tolerance is 5 points.
    config(['ml.auto_train_batch_size' => 1, 'ml.auto_train_rollback_tolerance' => 5]);
    $original = bootstrapModel();
    $original->update(['accuracy_score' => 100.0]);

    eligibleDoc('Job Order');

    $mock = Mockery::mock(ClassificationService::class)->makePartial();
    $mock->shouldReceive('train')->once()->andReturnUsing(function () use ($original) {
        MlModelRepository::where('is_active', true)->update(['is_active' => false]);

        return MlModelRepository::create([
            'model_name' => 'Support Vector Machine (SVM) + TF-IDF',
            'version' => 'v-test-small-dip',
            'accuracy_score' => 97.0,
            'model_file_path' => $original->model_file_path,
            'training_sample_count' => 16,
            'is_active' => true,
            'last_trained' => now(),
        ]);
    });

    $result = $mock->autoTrainIfDue();

    expect($result)->not->toBeNull()
        ->and($result['kept'])->toBeTrue();

    expect(MlModelRepository::active()->version)->toBe('v-test-small-dip');
});
