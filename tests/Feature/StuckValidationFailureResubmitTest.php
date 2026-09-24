<?php

use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Http\UploadedFile;

/**
 * Regression coverage for a real dead end: a document whose classification
 * confidence cleared the chance floor but whose category validation failed
 * (e.g. a missing required field) used to land in global_status
 * 'processing' with no way out — DocumentController::resubmit() only
 * accepted 'rejected'. See its own docblock for why 'processing' is safe
 * to treat the same way: it's never a transient "still working" state
 * once a document is visible outside WorkflowService::ingest()'s own
 * transaction.
 *
 * Pinned for the whole file — due_date is computed via now()->addDay(),
 * which is flaky against the real host clock (can land outside the 9
 * AM-5 PM business-hours window depending purely on what time the suite
 * happens to run — see AutomaticClassificationTieringTest.php's identical
 * fix for this exact failure mode).
 */
beforeEach(fn () => Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-08-12 10:00:00')));

test('a document stuck in processing after a validation failure can be resubmitted, same as a rejected one', function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Only Stage', 'sequence_order' => 1]);
    $approver = User::factory()->approver('Job Order')->create();
    $originator = User::factory()->originator()->create();

    $stuck = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'incomplete.txt',
        'file_path' => 'documents/incomplete.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'processing',
        'ml_category' => 'Job Order',
        'is_validated' => false,
        'validation_errors' => ['Missing required section/field: "Description of Work"'],
    ]);

    $response = $this->actingAs($originator)->post(route('originator.documents.resubmit', $stuck), [
        'file' => UploadedFile::fake()->createWithContent('fixed.txt',
            "Date Requested: July 16, 2026\nRequested By: Test Requester\nDescription of Work: "
            . str_repeat('fix the widget assembly line carefully and thoroughly ', 5)),
        'due_date' => now()->addDay()->format('Y-m-d\TH:i'),
    ]);

    $response->assertRedirect();
    $newVersion = $stuck->fresh()->nextVersion;
    expect($newVersion)->not->toBeNull()
        ->and($newVersion->previous_version_id)->toBe($stuck->document_id);
});

test('a document still genuinely mid-pipeline (never actually reached, but hypothetically) is not what this guard is meant to gate on — only rejected/processing are ever visible states', function () {
    // Sanity check the OTHER real states are still correctly rejected —
    // this guard must stay a strict allow-list, not silently open up to
    // every status.
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Only Stage', 'sequence_order' => 1]);
    $originator = User::factory()->originator()->create();

    $approved = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'done.txt',
        'file_path' => 'documents/done.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
    ]);

    $response = $this->actingAs($originator)->post(route('originator.documents.resubmit', $approved), [
        'file' => UploadedFile::fake()->createWithContent('x.txt', 'irrelevant'),
        'due_date' => now()->addDay()->format('Y-m-d\TH:i'),
    ]);

    $response->assertStatus(409);
});
