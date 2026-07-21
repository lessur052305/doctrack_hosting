<?php

use App\Services\ClassificationService;

/**
 * Regression coverage for switching accuracy_score from resubstitution
 * (testing the model on the exact samples it just trained on — which
 * only measures memorization, not real accuracy) to stratified k-fold
 * cross-validation (holding each fold out completely before scoring it).
 */
function distinctSamples(string $keyword, int $count): array
{
    return collect(range(1, $count))->map(
        fn ($i) => "{$keyword} reference number {$i}. This document concerns {$keyword} matters exclusively, "
            . "filed on a different date with different details each time, sample variant {$i} of {$count}."
    )->all();
}

test('train() returns a cross-validated accuracy between 0 and 100', function () {
    $samplesByCategory = [
        'Job Order' => distinctSamples('job order work request', 8),
        'Purchase Requisition' => distinctSamples('purchase requisition budget item', 8),
        'Service Report' => distinctSamples('service report technician findings', 8),
    ];

    $model = app(ClassificationService::class)->train($samplesByCategory);

    expect($model->accuracy_score)->toBeGreaterThanOrEqual(0.0)
        ->and($model->accuracy_score)->toBeLessThanOrEqual(100.0);
});

test('the final model still trains on every staged sample, not just cross-validation folds', function () {
    $samplesByCategory = [
        'Job Order' => distinctSamples('job order work request', 8),
        'Purchase Requisition' => distinctSamples('purchase requisition budget item', 8),
        'Service Report' => distinctSamples('service report technician findings', 8),
    ];

    $model = app(ClassificationService::class)->train($samplesByCategory);

    // 8 + 8 + 8 — cross-validation only ever holds out folds temporarily to
    // score itself; it must never shrink what the deployed model is built from.
    expect($model->training_sample_count)->toBe(24);
});

test('handles the smallest allowed category size (5 samples) without crashing', function () {
    $samplesByCategory = [
        'Job Order' => distinctSamples('job order work request', 5),
        'Purchase Requisition' => distinctSamples('purchase requisition budget item', 5),
        'Service Report' => distinctSamples('service report technician findings', 5),
    ];

    $model = app(ClassificationService::class)->train($samplesByCategory);

    expect($model->training_sample_count)->toBe(15)
        ->and($model->accuracy_score)->toBeGreaterThanOrEqual(0.0);
});

test('a genuinely well-separated dataset scores well under cross-validation', function () {
    // Distinct, non-overlapping vocabulary per category with enough samples
    // for meaningful folds — a classifier that learned anything at all
    // should score highly here, unlike a truly random guess (~33% with 3
    // balanced categories).
    $samplesByCategory = [
        'Job Order' => distinctSamples('aircon repair plumbing maintenance facilities work order', 10),
        'Purchase Requisition' => distinctSamples('laptop budget procurement requisition finance approval', 10),
        'Service Report' => distinctSamples('technician generator inspection findings completed service', 10),
    ];

    $model = app(ClassificationService::class)->train($samplesByCategory);

    expect($model->accuracy_score)->toBeGreaterThan(50.0);
});
