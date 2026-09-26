<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;

/**
 * global_status stays 'auto_approved' forever (a permanent record of HOW
 * the document got approved) — display_status is what the badge actually
 * shows, and should stop saying "Pending Review" the moment every
 * auto-approved stage has actually been reviewed. See DocumentRepository::
 * getDisplayStatusAttribute().
 */
test('an unreviewed auto-approved document still displays as auto_approved', function () {
    $originator = User::factory()->originator()->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Only Stage', 'sequence_order' => 1]);
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'doc.txt', 'file_path' => 'documents/doc.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addDay(), 'global_status' => 'auto_approved',
    ]);
    DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => null, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'auto_approved',
        'sla_expires_at' => now()->subHour(), 'acted_at' => now(), 'auto_approved' => true, 'admin_reviewed_at' => null, 'review_due_at' => now()->addHours(6),
    ]);

    expect($document->display_status)->toBe('auto_approved');
});

test('a fully-reviewed auto-approved document displays exactly like a real approval', function () {
    $originator = User::factory()->originator()->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Only Stage', 'sequence_order' => 1]);
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'doc.txt', 'file_path' => 'documents/doc.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addDay(), 'global_status' => 'auto_approved',
    ]);
    DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => null, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'auto_approved',
        'sla_expires_at' => now()->subHour(), 'acted_at' => now(), 'auto_approved' => true, 'admin_reviewed_at' => now(),
    ]);

    expect($document->display_status)->toBe('approved');
    // global_status itself is untouched — still the permanent record of HOW it was approved.
    expect($document->fresh()->global_status)->toBe('auto_approved');
});

test('a document auto-approved across two stages only displays as approved once BOTH are reviewed', function () {
    $originator = User::factory()->originator()->create();
    $stageOne = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Stage One', 'sequence_order' => 1]);
    $stageTwo = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Stage Two', 'sequence_order' => 2]);
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'doc.txt', 'file_path' => 'documents/doc.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addDay(), 'global_status' => 'auto_approved',
    ]);
    DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => null, 'stage_id' => $stageOne->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'auto_approved',
        'sla_expires_at' => now()->subHour(), 'acted_at' => now(), 'auto_approved' => true, 'admin_reviewed_at' => now(),
    ]);
    DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => null, 'stage_id' => $stageTwo->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'auto_approved',
        'sla_expires_at' => now()->subHour(), 'acted_at' => now(), 'auto_approved' => true, 'admin_reviewed_at' => null, 'review_due_at' => now()->addHours(6),
    ]);

    expect($document->display_status)->toBe('auto_approved');
});
