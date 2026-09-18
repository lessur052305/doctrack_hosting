<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\MlTimeEstimateModel;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\ApprovalTimeMlService;
use Carbon\Carbon;

/** Cycled across several real weekdays so the day-of-week feature actually varies — an all-identical column makes the regression's matrix singular. */
const ML_TEST_DAYS = ['2026-08-10 10:00:00', '2026-08-11 10:00:00', '2026-08-12 10:00:00', '2026-08-13 10:00:00', '2026-08-14 10:00:00'];

function mlDecidedAssignment(User $originator, User $approver, string $category, int $dayIndex, int $elapsedMinutes): DocumentAssignment
{
    test()->travelTo(Carbon::parse(ML_TEST_DAYS[$dayIndex % count(ML_TEST_DAYS)]));

    $stage = WorkflowStage::firstOrCreate(
        ['document_category' => $category, 'stage_name' => 'Only Stage'],
        ['sequence_order' => 1]
    );

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'ml.txt',
        'file_path' => 'documents/ml.txt',
        'mime_type' => 'text/plain',
        'ml_category' => $category,
        'is_validated' => true,
        'due_date' => now()->addDays(3),
        'global_status' => 'classified_validated',
    ]);

    return DocumentAssignment::create([
        'document_id' => $document->document_id,
        'user_id' => $approver->user_id,
        'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date,
        'priority_rank' => 2,
        'individual_status' => 'approved',
        'sla_expires_at' => now()->addHours(4),
        'acted_at' => now()->addMinutes($elapsedMinutes),
        'auto_approved' => false,
    ]);
}

/**
 * Both created_at AND acted_at fall outside business hours (9 PM), so
 * businessSecondsRemaining() measures exactly 0 regardless of how many
 * real wall-clock minutes passed — the exact "off-hours testing" pattern
 * that produced a misleadingly-confident 0-second model in real use.
 */
function offHoursAssignment(User $originator, User $approver, string $category, int $dayIndex, int $elapsedMinutes): DocumentAssignment
{
    test()->travelTo(Carbon::parse(ML_TEST_DAYS[$dayIndex % count(ML_TEST_DAYS)])->setTime(21, 0));

    $stage = WorkflowStage::firstOrCreate(
        ['document_category' => $category, 'stage_name' => 'Only Stage'],
        ['sequence_order' => 1]
    );

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'ml-offhours.txt',
        'file_path' => 'documents/ml-offhours.txt',
        'mime_type' => 'text/plain',
        'ml_category' => $category,
        'is_validated' => true,
        'due_date' => now()->addDays(3),
        'global_status' => 'classified_validated',
    ]);

    return DocumentAssignment::create([
        'document_id' => $document->document_id,
        'user_id' => $approver->user_id,
        'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date,
        'priority_rank' => 2,
        'individual_status' => 'approved',
        'sla_expires_at' => now()->addHours(4),
        'acted_at' => now()->addMinutes($elapsedMinutes),
        'auto_approved' => false,
    ]);
}

/** 24 decisions split between a fast and a slow approver, cycled across 5 different weekdays — comfortably over the 20-sample training floor with real feature variance. */
function seedTrainableCombo(string $category = 'Job Order', string $department = 'Engineering'): array
{
    $originator = User::factory()->originator()->create();
    $fast = User::factory()->approver($category)->create(['department' => $department]);
    $slow = User::factory()->approver($category)->create(['department' => $department]);

    for ($i = 0; $i < 12; $i++) {
        mlDecidedAssignment($originator, $fast, $category, $i, 5);
        mlDecidedAssignment($originator, $slow, $category, $i, 30);
    }

    return compact('originator', 'fast', 'slow');
}

test('trainableGroups is empty below the minimum sample count and includes the combo once it is reached', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['department' => 'Engineering']);

    for ($i = 0; $i < ApprovalTimeMlService::MIN_TRAINING_SAMPLES - 1; $i++) {
        mlDecidedAssignment($originator, $approver, 'Job Order', $i, 10);
    }
    expect(app(ApprovalTimeMlService::class)->trainableGroups())->toBeEmpty();

    mlDecidedAssignment($originator, $approver, 'Job Order', 0, 10);
    $groups = app(ApprovalTimeMlService::class)->trainableGroups();

    expect($groups)->toHaveCount(1)
        ->and($groups[0])->toBe(['ml_category' => 'Job Order', 'department' => 'Engineering']);
});

test('trainFor refuses to train below the minimum sample count', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['department' => 'Engineering']);
    mlDecidedAssignment($originator, $approver, 'Job Order', 0, 10);

    expect(app(ApprovalTimeMlService::class)->trainFor('Job Order', 'Engineering'))->toBeNull();
    expect(MlTimeEstimateModel::count())->toBe(0);
});

test('refuses to train when every decision on record measured exactly 0 business seconds', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['department' => 'Engineering']);

    for ($i = 0; $i < ApprovalTimeMlService::MIN_TRAINING_SAMPLES; $i++) {
        offHoursAssignment($originator, $approver, 'Job Order', $i, 10);
    }

    expect(app(ApprovalTimeMlService::class)->trainFor('Job Order', 'Engineering'))->toBeNull();
    expect(MlTimeEstimateModel::count())->toBe(0);
});

test('still refuses to train when non-zero decisions exist but fall short of the training floor themselves', function () {
    // 9 off-hours (zero) + 1 real — passes the raw row-count floor (10
    // total) but only has 1 GENUINELY non-zero reading, nowhere near the
    // training floor. A milder, more realistic version of "mostly
    // contaminated" than the all-zero extreme the test above covers.
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['department' => 'Engineering']);

    for ($i = 0; $i < ApprovalTimeMlService::MIN_TRAINING_SAMPLES - 1; $i++) {
        offHoursAssignment($originator, $approver, 'Job Order', $i, 10);
    }
    mlDecidedAssignment($originator, $approver, 'Job Order', 0, 10);

    expect(app(ApprovalTimeMlService::class)->trainFor('Job Order', 'Engineering'))->toBeNull();
});

test('trains normally once the training floor is met by genuinely non-zero decisions, even with extra off-hours ones mixed in', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['department' => 'Engineering']);

    // A full training floor's worth of REAL decisions...
    for ($i = 0; $i < ApprovalTimeMlService::MIN_TRAINING_SAMPLES; $i++) {
        mlDecidedAssignment($originator, $approver, 'Job Order', $i, 10);
    }
    // ...plus a few contaminated off-hours ones mixed in — real Ridge
    // Regression noise-tolerance handles a minority of these fine once
    // there's already enough genuine signal to trust.
    for ($i = 0; $i < 3; $i++) {
        offHoursAssignment($originator, $approver, 'Job Order', $i, 10);
    }

    expect(app(ApprovalTimeMlService::class)->trainFor('Job Order', 'Engineering'))->not->toBeNull();
});

test('trains successfully right at the (now lower) minimum sample count, exercising 5-fold cross-validation at its smallest fold sizes', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['department' => 'Engineering']);

    for ($i = 0; $i < ApprovalTimeMlService::MIN_TRAINING_SAMPLES; $i++) {
        mlDecidedAssignment($originator, $approver, 'Job Order', $i, 10);
    }

    $model = app(ApprovalTimeMlService::class)->trainFor('Job Order', 'Engineering');

    expect($model)->not->toBeNull()
        // The FINAL model trains on every row, not a held-back 80% slice
        // — see trainFor()'s own docblock update.
        ->and($model->training_sample_count)->toBe(ApprovalTimeMlService::MIN_TRAINING_SAMPLES);
});

test('trainFor fits and activates a model once there is enough real decision history', function () {
    seedTrainableCombo();

    $model = app(ApprovalTimeMlService::class)->trainFor('Job Order', 'Engineering');

    expect($model)->not->toBeNull()
        ->and($model->is_active)->toBeTrue()
        ->and($model->training_sample_count)->toBe(24)
        ->and($model->coefficients)->toHaveCount(2)
        // The fast/slow split is deterministic (no noise), so a correctly
        // fit model should predict close to the truth on held-out data.
        ->and($model->mae_seconds)->toBeLessThan(120);
});

test('trainFor does not replace an existing model that is already at least as good', function () {
    seedTrainableCombo();

    // An unbeatable existing model — nothing trained from noisy real data
    // can score a lower (better) MAE than zero.
    MlTimeEstimateModel::create([
        'ml_category' => 'Job Order', 'department' => 'Engineering', 'version' => 'v-existing',
        'intercept' => 0, 'coefficients' => [1, 0], 'mae_seconds' => 0,
        'training_sample_count' => 24, 'is_active' => true, 'trained_at' => now(),
    ]);

    $result = app(ApprovalTimeMlService::class)->trainFor('Job Order', 'Engineering');

    expect($result)->toBeNull();
    expect(MlTimeEstimateModel::where('version', 'v-existing')->first()->is_active)->toBeTrue();
});

test('predictNextDecision returns null when no model is active for the combo', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['department' => 'Engineering']);

    $prediction = app(ApprovalTimeMlService::class)
        ->predictNextDecision('Job Order', 'Engineering', collect([$approver]));

    expect($prediction)->toBeNull();
});

test('predictNextDecision uses whichever eligible approver has the slower historical average', function () {
    ['fast' => $fast, 'slow' => $slow] = seedTrainableCombo();
    app(ApprovalTimeMlService::class)->trainFor('Job Order', 'Engineering');

    $predictedFastOnly = app(ApprovalTimeMlService::class)
        ->predictNextDecision('Job Order', 'Engineering', collect([$fast]));
    $predictedBoth = app(ApprovalTimeMlService::class)
        ->predictNextDecision('Job Order', 'Engineering', collect([$fast, $slow]));

    expect($predictedFastOnly)->not->toBeNull()
        ->and($predictedBoth)->not->toBeNull()
        // Pairing the fast approver with the slow one should never predict
        // FASTER than the fast approver alone — the slow one can only pull
        // the "worst eligible approver" prediction up, never down.
        ->and($predictedBoth)->toBeGreaterThanOrEqual($predictedFastOnly);
});

test('the scheduled command trains every eligible combo and reports how many models it activated', function () {
    seedTrainableCombo();

    $this->artisan('ml:train-time-estimator')
        ->expectsOutputToContain('1 eligible combo(s) checked, 1 model(s)')
        ->assertExitCode(0);

    expect(MlTimeEstimateModel::where('is_active', true)->count())->toBe(1);
});

test('ApprovalForecastService uses the trained model for the immediate next stage once one is active', function () {
    seedTrainableCombo();
    app(ApprovalTimeMlService::class)->trainFor('Job Order', 'Engineering');

    $originator = User::factory()->originator()->create();
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'live.txt',
        'file_path' => 'documents/live.txt',
        'mime_type' => 'text/plain',
        'ml_category' => 'Job Order',
        'is_validated' => true,
        'due_date' => now()->addDays(3),
        'global_status' => 'classified_validated',
    ]);
    app(\App\Services\WorkflowService::class)->routeToWorkflow($document);

    $estimate = app(\App\Services\ApprovalForecastService::class)->estimateFor($document->fresh());

    expect($estimate)->not->toBeNull()
        ->and($estimate->totalSeconds)->toBeGreaterThan(0);
});

test('the ML Training page shows the Estimated Approval Time status with no train-now control', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['department' => 'Engineering']);
    mlDecidedAssignment($originator, $approver, 'Job Order', 0, 10);

    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('admin.ml.training'));

    $response->assertOk()
        ->assertSee('Estimated Approval Time Models')
        ->assertSee('Job Order')
        ->assertSee('1/'.ApprovalTimeMlService::MIN_TRAINING_SAMPLES);
});
