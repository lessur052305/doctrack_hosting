<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\PerformanceInsightsService;

/**
 * Creates one real, decided assignment. $elapsedMinutes is exactly how
 * long the decision took (created_at -> acted_at), landing entirely
 * inside the same business-hours window the test travels to first, so
 * PerformanceInsightsService's business-hours-aware average comes back
 * as exactly $elapsedMinutes * 60 seconds — no cross-day arithmetic to
 * reason about.
 */
function decidedAssignment(User $originator, User $approver, string $category, int $elapsedMinutes, bool $autoApproved = false): DocumentAssignment
{
    $stage = WorkflowStage::firstOrCreate(
        ['document_category' => $category, 'stage_name' => 'Only Stage'],
        ['sequence_order' => 1]
    );

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'perf.txt',
        'file_path' => 'documents/perf.txt',
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
        'individual_status' => $autoApproved ? 'auto_approved' : 'approved',
        'sla_expires_at' => now()->addHours(4),
        'acted_at' => now()->addMinutes($elapsedMinutes),
        'auto_approved' => $autoApproved,
    ]);
}

beforeEach(function () {
    // Wednesday mid-morning — a fixed, known-safe business-hours moment so
    // every $elapsedMinutes offset above lands inside the same working
    // window regardless of when the test suite itself happens to run.
    $this->travelTo(\Carbon\Carbon::parse('2026-08-12 10:00:00'));
});

test('ranks approvers fastest-first by their average real decision time', function () {
    $originator = User::factory()->originator()->create();
    $fast = User::factory()->approver('Job Order')->create(['full_name' => 'Fast Approver']);
    $slow = User::factory()->approver('Job Order')->create(['full_name' => 'Slow Approver']);

    foreach ([5, 10, 15] as $minutes) {
        decidedAssignment($originator, $fast, 'Job Order', $minutes);
    }
    foreach ([50, 55, 60] as $minutes) {
        decidedAssignment($originator, $slow, 'Job Order', $minutes);
    }

    $ranked = app(PerformanceInsightsService::class)->fastestApprovers();

    expect($ranked)->toHaveCount(2)
        ->and($ranked[0]['label'])->toBe('Fast Approver')
        ->and($ranked[0]['avg_seconds'])->toBe(10 * 60) // avg(5,10,15)
        ->and($ranked[1]['label'])->toBe('Slow Approver')
        ->and($ranked[1]['avg_seconds'])->toBe(55 * 60); // avg(50,55,60)
});

test('excludes an approver whose decisions all measured exactly 0 business seconds instead of showing a misleading "fastest" 0s', function () {
    // Both created_at and acted_at fall in the same evening moment,
    // outside business hours — real wall-clock minutes pass, but none of
    // it is business time, the exact pattern that produced a confusing
    // "0s" ranking in real use.
    $this->travelTo(\Carbon\Carbon::parse('2026-08-12 21:00:00'));
    $originator = User::factory()->originator()->create();
    $offHours = User::factory()->approver('Job Order')->create(['full_name' => 'Off Hours Approver']);

    foreach ([5, 10, 15] as $minutes) {
        decidedAssignment($originator, $offHours, 'Job Order', $minutes);
    }

    $ranked = app(PerformanceInsightsService::class)->fastestApprovers();

    expect($ranked)->toBeEmpty();
});

test('excludes an approver whose real (non-zero) decisions fall short of MIN_DECISIONS, even mixed with off-hours ones', function () {
    // 2 off-hours (zero) + 1 real — 3 total passes the old raw-count
    // floor, but only 1 is GENUINELY non-zero, short of MIN_DECISIONS
    // (3). A milder, more realistic version of "mostly zero-contaminated"
    // than the all-zero extreme the test above covers.
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['full_name' => 'Mostly Off Hours']);

    $this->travelTo(\Carbon\Carbon::parse('2026-08-12 21:00:00'));
    decidedAssignment($originator, $approver, 'Job Order', 5);
    decidedAssignment($originator, $approver, 'Job Order', 10);

    $this->travelTo(\Carbon\Carbon::parse('2026-08-13 10:00:00'));
    decidedAssignment($originator, $approver, 'Job Order', 15);

    $ranked = app(PerformanceInsightsService::class)->fastestApprovers();

    expect($ranked)->toBeEmpty();
});

test('ranks an approver once MIN_DECISIONS genuinely non-zero readings exist, even with extra off-hours ones mixed in', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['full_name' => 'Mostly Real']);

    $this->travelTo(\Carbon\Carbon::parse('2026-08-12 21:00:00'));
    decidedAssignment($originator, $approver, 'Job Order', 5); // contaminated, off-hours

    $this->travelTo(\Carbon\Carbon::parse('2026-08-13 10:00:00'));
    foreach ([10, 15, 20] as $minutes) {
        decidedAssignment($originator, $approver, 'Job Order', $minutes);
    }

    $ranked = app(PerformanceInsightsService::class)->fastestApprovers();

    expect($ranked)->toHaveCount(1)
        ->and($ranked[0]['label'])->toBe('Mostly Real');
});

test('excludes an approver with fewer than the minimum number of real decisions', function () {
    $originator = User::factory()->originator()->create();
    $sparse = User::factory()->approver('Job Order')->create(['full_name' => 'Barely Seen']);

    decidedAssignment($originator, $sparse, 'Job Order', 5);
    decidedAssignment($originator, $sparse, 'Job Order', 10);

    $ranked = app(PerformanceInsightsService::class)->fastestApprovers();

    expect($ranked)->toBeEmpty();
});

test('excludes auto-approved assignments from the average entirely', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['full_name' => 'Mixed Approver']);

    // Three fast real decisions...
    foreach ([5, 5, 5] as $minutes) {
        decidedAssignment($originator, $approver, 'Job Order', $minutes);
    }
    // ...and one very slow auto-approval, which must NOT drag the average up.
    decidedAssignment($originator, $approver, 'Job Order', 600, autoApproved: true);

    $ranked = app(PerformanceInsightsService::class)->fastestApprovers();

    expect($ranked)->toHaveCount(1)
        ->and($ranked[0]['avg_seconds'])->toBe(5 * 60)
        ->and($ranked[0]['decisions_count'])->toBe(3);
});

test('groups the fastest-department ranking by department and skips approvers with no department set', function () {
    $originator = User::factory()->originator()->create();
    $engA = User::factory()->approver('Job Order')->create(['department' => 'Engineering']);
    $engB = User::factory()->approver('Job Order')->create(['department' => 'Engineering']);
    $finance = User::factory()->approver('Job Order')->create(['department' => 'Finance']);
    $noDept = User::factory()->approver('Job Order')->create(['department' => null]);

    foreach ([10, 10, 10] as $minutes) {
        decidedAssignment($originator, $engA, 'Job Order', $minutes);
    }
    foreach ([20, 20, 20] as $minutes) {
        decidedAssignment($originator, $engB, 'Job Order', $minutes);
    }
    foreach ([90, 90, 90] as $minutes) {
        decidedAssignment($originator, $finance, 'Job Order', $minutes);
    }
    foreach ([1, 1, 1] as $minutes) {
        decidedAssignment($originator, $noDept, 'Job Order', $minutes);
    }

    $ranked = app(PerformanceInsightsService::class)->fastestDepartments();

    expect($ranked)->toHaveCount(2)
        ->and($ranked[0]['label'])->toBe('Engineering')
        ->and($ranked[0]['avg_seconds'])->toBe(15 * 60) // avg of both Engineering approvers' decisions
        ->and($ranked[0]['decisions_count'])->toBe(6)
        ->and($ranked[1]['label'])->toBe('Finance');
});

test('ranks document categories by how fast they typically get decided', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();

    foreach ([5, 5, 5] as $minutes) {
        decidedAssignment($originator, $approver, 'Job Order', $minutes);
    }
    foreach ([200, 200, 200] as $minutes) {
        decidedAssignment($originator, $approver, 'Purchase Requisition', $minutes);
    }

    $ranked = app(PerformanceInsightsService::class)->fastestCategories();

    expect($ranked)->toHaveCount(2)
        ->and($ranked[0]['label'])->toBe('Job Order')
        ->and($ranked[1]['label'])->toBe('Purchase Requisition');
});

test('the Performance Insights page renders all three rankings for an admin', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['full_name' => 'Visible Approver', 'department' => 'Engineering']);
    foreach ([5, 6, 7] as $minutes) {
        decidedAssignment($originator, $approver, 'Job Order', $minutes);
    }

    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('admin.performance.insights'));

    $response->assertOk()
        ->assertSee('Performance Insights')
        ->assertSee('Fastest Approvers')
        ->assertSee('Fastest Departments')
        ->assertSee('Fastest Categories')
        ->assertSee('Visible Approver');
});

test('the refresh endpoint returns only the results fragment', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('admin.performance.insights.refresh'));

    $response->assertOk()->assertDontSee('<html', false);
});

test('the poll endpoint reports the latest real decision timestamp', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    decidedAssignment($originator, $approver, 'Job Order', 5);

    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->getJson(route('admin.performance.insights.poll'));

    $response->assertOk()->assertJson(['latest' => now()->addMinutes(5)->toDateTimeString()]);
});
