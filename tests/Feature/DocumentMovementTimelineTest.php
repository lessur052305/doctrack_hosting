<?php

use App\Models\AuditLog;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\DocumentReviewSession;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\DocumentMovementTimeline;

/**
 * Regression coverage for the nested per-document movement timeline —
 * merges audit-log events with review-session open/close events into one
 * chronological feed, shared by the Admin Document Tracking module, the
 * Audit Logs nested view, and (as a table) the Originator's Audit Trail.
 * Verified live during development but never left with a permanent test.
 */
it('merges audit log entries and review session events into one chronological, labeled feed', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'timeline-test.txt',
        'file_path' => 'documents/timeline-test.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
    ]);

    AuditLog::record($originator->user_id, $document->document_id, 'upload', 'Document uploaded.');
    AuditLog::record(null, $document->document_id, 'classify', 'Classified as Job Order.');

    DocumentReviewSession::create([
        'document_id' => $document->document_id,
        'user_id' => $approver->user_id,
        'opened_at' => now()->subMinutes(5),
        'closed_at' => now()->subMinutes(2),
        'duration_seconds' => 180,
        'session_type' => 'initial',
    ]);

    $events = DocumentMovementTimeline::build($document->fresh());

    expect($events->pluck('label')->all())->toContain('Uploaded', 'Classified', 'Review Activity');
    $reviewEvent = $events->firstWhere('label', 'Review Activity');
    expect($reviewEvent['category'])->toBe('review');
    expect($reviewEvent['kind'])->toBe('session_group');
    expect($reviewEvent['note'])->toContain('1 pass')->toContain('3m 0s total');
    expect($events->firstWhere('label', 'Uploaded')['category'])->toBe('lifecycle');
    // Sorted newest-first.
    expect($events->first()['timestamp']->gte($events->last()['timestamp']))->toBeTrue();
});

it('groups every one of a person\'s review passes into a single row instead of one row per open/close', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'multi-pass-test.txt',
        'file_path' => 'documents/multi-pass-test.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
    ]);

    DocumentReviewSession::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id,
        'opened_at' => now()->subMinutes(30), 'closed_at' => now()->subMinutes(28),
        'duration_seconds' => 120, 'session_type' => 'initial',
    ]);
    DocumentReviewSession::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id,
        'opened_at' => now()->subMinutes(10), 'closed_at' => now()->subMinutes(9),
        'duration_seconds' => 60, 'session_type' => 'follow_up',
    ]);
    // A third, still-open pass — no closed_at yet.
    DocumentReviewSession::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id,
        'opened_at' => now()->subMinute(), 'closed_at' => null,
        'duration_seconds' => null, 'session_type' => 'follow_up',
    ]);

    $events = DocumentMovementTimeline::build($document->fresh());
    $reviewEvents = $events->where('label', 'Review Activity');

    expect($reviewEvents)->toHaveCount(1);
    $group = $reviewEvents->first();
    expect($group['actor'])->toBe($approver->full_name);
    expect($group['note'])->toContain('3 passes')->toContain('3m 0s total')->toContain('still reviewing');
    // Every individual pass is preserved in the expandable detail, not lost.
    expect(substr_count($group['passes_detail'], '–'))->toBeGreaterThanOrEqual(3);
});

it('keeps true insertion order for audit events that land in the exact same second, instead of a scrambled tie order', function () {
    // Regression: audit_logs.timestamp is second-precision, and a single
    // WorkflowService::ingest() call fires several rows (upload, validate,
    // classify, route per stage) synchronously within the same second —
    // without a secondary sort key on log_id, MySQL/the collection sort
    // has no defined order for those ties.
    $originator = User::factory()->originator()->create();
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'same-second-test.txt',
        'file_path' => 'documents/same-second-test.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
    ]);

    $now = now();
    // All four rows pinned to the identical second — exactly the tie
    // scenario a real ingest() call produces. AuditLog is insert-only
    // (see its booted() guard), so the timestamp is forced via the query
    // builder directly rather than updating the Eloquent model.
    foreach ([
        ['upload', 'Document uploaded.'],
        ['validate', 'Validation passed.'],
        ['classify', 'Classified as Job Order.'],
        ['route', "Stage 'Review': assigned."],
    ] as [$action, $description]) {
        $log = AuditLog::record($originator->user_id, $document->document_id, $action, $description);
        \Illuminate\Support\Facades\DB::table('audit_logs')->where('log_id', $log->log_id)->update(['timestamp' => $now]);
    }

    $events = DocumentMovementTimeline::build($document->fresh());
    $labels = $events->whereIn('label', ['Uploaded', 'Validated', 'Classified', 'Routed'])->pluck('label')->values()->all();

    // Newest-first, so true insertion order (upload -> validate -> classify
    // -> route) must come back reversed.
    expect($labels)->toBe(['Routed', 'Classified', 'Validated', 'Uploaded']);
});

it('renders the nested timeline correctly on the Admin Document Tracking module', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'tracking-nested-test.txt',
        'file_path' => 'documents/tracking-nested-test.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'processing',
        'ml_category' => null,
    ]);
    AuditLog::record($originator->user_id, $document->document_id, 'upload', 'Document uploaded.');

    $response = $this->actingAs($admin)->get(route('documents.track', $document));

    $response->assertOk();
    $response->assertSee('tracking-nested-test.txt');
    $response->assertSee('Uploaded');
});

it('renders the Document Tracker popup trigger correctly on the Audit Logs page', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'audit-nested-test.txt',
        'file_path' => 'documents/audit-nested-test.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'processing',
        'ml_category' => null,
    ]);
    AuditLog::record($originator->user_id, $document->document_id, 'upload', 'Document uploaded.');

    $response = $this->actingAs($admin)->get(route('admin.audit.logs'));

    $response->assertOk();
    $response->assertSee('audit-nested-test.txt');
    // The old "expand inline" trigger text is gone — Audit Logs now opens
    // the shared Document Tracker popup instead (see
    // resources/views/components/kpi-drilldown-modal.blade.php and
    // routes/web.php's documents.trackerModal) rather than expanding the
    // full table inline with no guaranteed room to show it in.
    $response->assertSee('Click to view Document Tracker.');
});
