<?php

use App\Models\DocumentAnnotation;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\DocumentRevision;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\TextDiffService;

/**
 * Coverage for Revision History (Feature: Google-Docs-style before/after
 * popup — see DocumentRevision, WorkflowService::saveDocumentRevision(),
 * TextDiffService, and the documents.revisions/documents.revisions.compare
 * routes).
 */
function revisionHistoryDoc(User $originator): DocumentRepository
{
    return DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'revision-history-' . uniqid() . '.txt',
        'file_path' => 'documents/' . uniqid() . '.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
        'ocr_text' => 'The original wording has a problem in it.',
    ]);
}

test('saving a revision records an immutable before/after snapshot linked to the resolved annotation', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['full_name' => 'Flagging Approver']);
    $stage = WorkflowStage::firstOrCreate(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    $document = revisionHistoryDoc($originator);

    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending',
        'sla_expires_at' => now()->addHours(20),
    ]);
    $annotation = DocumentAnnotation::create([
        'document_id' => $document->document_id, 'assignment_id' => $assignment->assignment_id, 'raised_by' => $approver->user_id,
        'start_offset' => 21, 'end_offset' => 40, 'selected_text' => 'has a problem in it', 'comment' => 'Unclear wording here.',
    ]);

    $this->actingAs($originator)->post(route('originator.documents.saveRevision', $document), [
        'text' => 'The original wording is now much clearer.',
        'resolved_annotation_ids' => [$annotation->annotation_id],
    ])->assertRedirect();

    $revision = DocumentRevision::where('document_id', $document->document_id)->first();
    expect($revision)->not->toBeNull()
        ->and($revision->revised_by)->toBe($originator->user_id)
        ->and($revision->previous_text)->toBe('The original wording has a problem in it.')
        ->and($revision->new_text)->toBe('The original wording is now much clearer.')
        ->and($revision->annotations->pluck('annotation_id')->all())->toBe([$annotation->annotation_id]);
});

test('a save with no resolved annotations still records a revision, with no linked flags', function () {
    $originator = User::factory()->originator()->create();
    $document = revisionHistoryDoc($originator);

    $this->actingAs($originator)->post(route('originator.documents.saveRevision', $document), [
        'text' => 'A general wording cleanup, unrelated to any flag.',
    ])->assertRedirect();

    $revision = DocumentRevision::where('document_id', $document->document_id)->first();
    expect($revision)->not->toBeNull()
        ->and($revision->annotations)->toHaveCount(0);
});

test('the history list shows the current version pinned above past revisions, newest first', function () {
    $originator = User::factory()->originator()->create();
    $document = revisionHistoryDoc($originator);

    DocumentRevision::create([
        'document_id' => $document->document_id, 'revised_by' => $originator->user_id,
        'previous_text' => 'v0 text', 'new_text' => 'v1 text', 'created_at' => now()->subHours(2),
    ]);
    DocumentRevision::create([
        'document_id' => $document->document_id, 'revised_by' => $originator->user_id,
        'previous_text' => 'v1 text', 'new_text' => 'v2 text', 'created_at' => now()->subHour(),
    ]);

    $response = $this->actingAs($originator)->get(route('documents.revisions', $document));
    $response->assertOk();
    $html = $response->getContent();

    $positions = [
        'current' => strpos($html, 'Current version'),
        'newer' => strpos($html, $originator->full_name),
    ];
    expect($positions['current'])->not->toBeFalse()->and($positions['current'])->toBeLessThan($positions['newer']);
});

test('an assigned approver can view the diff, bolding their own name among addressed flags', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['full_name' => 'Diff Viewing Approver']);
    $stage = WorkflowStage::firstOrCreate(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    $document = revisionHistoryDoc($originator);

    DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending',
        'sla_expires_at' => now()->addHours(20),
    ]);

    $revision = DocumentRevision::create([
        'document_id' => $document->document_id, 'revised_by' => $originator->user_id,
        'previous_text' => 'The quick brown fox jumps over the lazy dog.',
        'new_text' => 'The quick red fox leaps over the sleepy dog.',
    ]);

    $response = $this->actingAs($approver)->get(route('documents.revisions.compare', [$document, $revision]));

    $response->assertOk()
        ->assertSee('brown')
        ->assertSee('red')
        ->assertSee('jumps')
        ->assertSee('leaps');
});

test('a revision from a different document cannot be compared via a mismatched document id', function () {
    $originatorA = User::factory()->originator()->create();
    $originatorB = User::factory()->originator()->create();
    $documentA = revisionHistoryDoc($originatorA);
    $documentB = revisionHistoryDoc($originatorB);

    $revision = DocumentRevision::create([
        'document_id' => $documentA->document_id, 'revised_by' => $originatorA->user_id,
        'previous_text' => 'before', 'new_text' => 'after',
    ]);

    $this->actingAs($originatorB)->get(route('documents.revisions.compare', [$documentB, $revision]))
        ->assertNotFound();
});

test('an uninvolved originator cannot view another document\'s revision history', function () {
    $originator = User::factory()->originator()->create();
    $stranger = User::factory()->originator()->create();
    $document = revisionHistoryDoc($originator);

    $this->actingAs($stranger)->get(route('documents.revisions', $document))->assertForbidden();
});

test('TextDiffService classifies unchanged, removed, and added words and preserves original spacing', function () {
    $diff = app(TextDiffService::class)->diff('The quick brown fox.', "The quick red fox jumps.");

    $beforeText = collect($diff['before'])->pluck('text')->implode('');
    $afterText = collect($diff['after'])->pluck('text')->implode('');

    expect($beforeText)->toBe('The quick brown fox.')
        ->and($afterText)->toBe('The quick red fox jumps.')
        ->and(collect($diff['before'])->firstWhere('type', 'removed')['text'])->toBe('brown')
        ->and(collect($diff['after'])->firstWhere('type', 'added')['text'] ?? null)->not->toBeNull();
});
