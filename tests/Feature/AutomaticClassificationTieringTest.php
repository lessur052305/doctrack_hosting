<?php

use App\Models\DocumentAssignment;
use App\Models\NotificationRecord;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\ClassificationService;
use App\Services\WorkflowService;
use Illuminate\Http\UploadedFile;

// Pinned for the whole file — several tests compute due_date via
// now()->addDay(), which is flaky against the real host clock (can land
// outside the 9 AM-5 PM business-hours window depending purely on what
// time the suite happens to run — confirmed reproducing this exact
// failure mode once already this session).
beforeEach(fn () => Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-08-12 10:00:00')));

/**
 * Coverage for WorkflowService::ingest()'s automatic tiering (Feature: no
 * manual admin review) — replaces the old confidence-only hold. A document
 * is trusted and routed automatically whenever EITHER its confidence is
 * high, OR its margin over the runner-up category is wide enough even at
 * moderate confidence; it's only rejected as genuinely ambiguous when
 * BOTH are weak. See WorkflowService::ingest()'s $isAmbiguous docblock and
 * ClassificationService::predictConfidenceAndMargin().
 */
function tieringDoc(float $confidence, float $margin, string $content): array
{
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Only Stage', 'sequence_order' => 1]);
    $approver = User::factory()->approver('Job Order')->create();
    $originator = User::factory()->originator()->create();

    $mock = Mockery::mock(ClassificationService::class);
    $mock->shouldReceive('classify')->andReturn(['category' => 'Job Order', 'confidence' => $confidence, 'margin' => $margin, 'model_id' => null]);
    app()->instance(ClassificationService::class, $mock);

    $document = app(WorkflowService::class)->ingest(
        UploadedFile::fake()->createWithContent('memo.txt', $content),
        $originator,
        now()->addDay()->toDateTimeString(),
    );

    return compact('document', 'approver', 'originator');
}

// Matches the Job Order template's required_sections + min_word_count —
// validation itself isn't under test here, so this stays constant across
// cases and is deliberately content that WOULD pass, so a rejection in
// any of these tests is unambiguously the tiering logic, not validation.
function tieringContent(): string
{
    return "Job Order No: JO-1\nDate Requested: today\nRequested By: someone\nDescription of Work: "
        . str_repeat('fix the widget assembly line carefully and thoroughly ', 5);
}

test('high confidence routes automatically and adds nothing ambiguous to the record, regardless of margin', function () {
    ['document' => $document] = tieringDoc(confidence: 90.0, margin: 3.0, content: tieringContent());

    expect($document->global_status)->toBe('classified_validated')
        ->and($document->ml_margin)->toBe(3.0)
        ->and(DocumentAssignment::where('document_id', $document->document_id)->count())->toBe(1);
});

test('moderate confidence with a clear margin over the runner-up is still trusted and routed automatically', function () {
    // Below review_confidence_threshold (70) on its own, but a wide
    // enough lead over the runner-up category that the guess is still
    // trustworthy — this is the new capability the old confidence-only
    // gate couldn't express.
    ['document' => $document] = tieringDoc(confidence: 45.0, margin: 25.0, content: tieringContent());

    expect($document->global_status)->toBe('classified_validated')
        ->and(DocumentAssignment::where('document_id', $document->document_id)->count())->toBe(1);
});

test('moderate confidence with a flat margin is rejected as ambiguous, not routed', function () {
    ['document' => $document, 'originator' => $originator] = tieringDoc(confidence: 45.0, margin: 8.0, content: tieringContent());

    expect($document->global_status)->toBe('rejected')
        ->and(DocumentAssignment::where('document_id', $document->document_id)->count())->toBe(0)
        ->and(NotificationRecord::where('recipient_id', $originator->user_id)
            ->where('document_id', $document->document_id)
            ->where('message_body', 'like', "%doesn't clearly match any of our trained categories%")
            ->exists())->toBeTrue();
});

test('a genuinely ambiguous document can be resubmitted with an explicit routing_mode choice', function () {
    ['document' => $document, 'originator' => $originator] = tieringDoc(confidence: 40.0, margin: 5.0, content: tieringContent());
    expect($document->global_status)->toBe('rejected');

    // 'unrelated' specifically — since the classifier is still mocked to
    // the same ambiguous result, this proves the originator's own
    // routing_mode choice is what gets them past the ambiguity this
    // time (routing_mode 'unrelated' is exempt from the classification-
    // ambiguity check entirely — see ingest()'s $isAmbiguous docblock),
    // not a lucky re-roll of the classifier's guess.
    $response = $this->actingAs($originator)->post(route('originator.documents.resubmit', $document), [
        'file' => UploadedFile::fake()->createWithContent('revised.txt', tieringContent()),
        'due_date' => now()->addDay()->format('Y-m-d\TH:i'),
        'routing_mode' => 'unrelated',
    ]);

    $response->assertRedirect();
    $newDocument = $document->fresh()->nextVersion;
    expect($newDocument)->not->toBeNull()
        ->and($newDocument->desired_routing)->toBe('unrelated')
        ->and($newDocument->global_status)->not->toBe('rejected')
        ->and($newDocument->pending_custom_routing_at)->not->toBeNull();
});
