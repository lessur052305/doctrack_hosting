<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\MlStagingSample;
use App\Models\NotificationRecord;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\ClassificationService;
use Illuminate\Http\UploadedFile;

/**
 * Coverage for the content-readability review queue (mirrors
 * MlReviewQueueTest's low-confidence review coverage). WorkflowService::
 * ingest() holds a document — no assignment created — when the readability
 * heuristic (ValidationService::checkContentQuality(), scored against a
 * category's staged MlStagingSample vocabulary) is the SOLE reason
 * validation failed. AdminController::reviewReadability() confirms
 * (stages it into MlStagingSample, growing the vocabulary, and routes it)
 * or rejects it (sets global_status to 'rejected', reusing the existing
 * resubmit flow) — same as the classification-confidence review queue.
 */
function readabilityAdmin(): User
{
    return User::factory()->admin()->create();
}

function stageVocabulary(string $category, int $count = 5): void
{
    $base = [
        'Job Order' => 'Job Order No: JO-2026-0500. Date Requested: January 1, 2026. Requested By: Sample Requester. '
            . 'Description of Work: Perform scheduled servicing on the company delivery truck. Change the engine oil and '
            . 'oil filter, inspect the brake pads, check the transmission fluid, rotate the tires, and test the battery charge.',
        'Purchase Requisition' => 'This requisition covers replacement office supplies for the finance department, including '
            . 'printer toner cartridges, binder clips, and letterhead stationery within the approved quarterly budget.',
        'Service Report' => 'The technician performed a full diagnostic of the HVAC unit on the second floor, cleaned the '
            . 'condenser coils, replaced the air filter, and confirmed stable operating temperature before closing the ticket.',
    ];

    for ($i = 0; $i < $count; $i++) {
        MlStagingSample::create([
            'category' => $category,
            'original_filename' => "sample-{$i}.txt",
            'extracted_text' => $base[$category] . " Reference number {$i}.",
        ]);
    }
}

/** Ingests real $text through the real extraction+validation pipeline, mocking only the classifier's category/confidence. */
function ingestWithText(string $category, string $text, float $confidence = 95.0): DocumentRepository
{
    $originator = User::factory()->originator()->create();

    $mock = Mockery::mock(ClassificationService::class);
    $mock->shouldReceive('classify')->andReturn(['category' => $category, 'confidence' => $confidence, 'margin' => 100.0, 'model_id' => null]);
    // Stubbed too — AdminController::confirmReadabilityReview()'s
    // near-duplicate-in-staging check calls this when a held document is
    // later confirmed via the admin review tests below.
    $mock->shouldReceive('wordOverlapSimilarity')->andReturn(0.0);
    app()->instance(ClassificationService::class, $mock);

    return app(App\Services\WorkflowService::class)->ingest(
        UploadedFile::fake()->createWithContent('test.txt', $text),
        $originator,
        now()->addDay()->toDateTimeString(),
    );
}

const READABILITY_GOOD_JOB_ORDER = "JOB ORDER\nJob Order No: JO-2026-9001\nDate Requested: March 1, 2026\n"
    . "Requested By: Test Requester\nDescription of Work:\n"
    . "Perform scheduled servicing on the company delivery truck. Change the engine oil and oil filter, "
    . "inspect the brake pads, check the transmission fluid, rotate the tires, and test the battery charge.";

const READABILITY_GARBLED_JOB_ORDER = "JOB ORDER\nJob Order No: JO-2026-9002\nDate Requested: March 1, 2026\n"
    . "Requested By: Test Requester\nDescription of Work: "
    . "xkzq vbnm qzxc wplo zxvb mnbv qwop lkjh zxcv bnml qazw sxed cvfr tgby hnuj mkio plaz wsxq edcr fvtg "
    . "bnhy ujmk iolp zaws xedc rfvt gbnh yujm plqw osie ktrn vbmz xolp qwer tyui";

it('holds a document for readability review when it is the sole validation failure', function () {
    stageVocabulary('Job Order');

    $document = ingestWithText('Job Order', READABILITY_GARBLED_JOB_ORDER);

    expect($document->readability_review_status)->toBe('pending')
        ->and($document->readability_score)->not->toBeNull()
        ->and($document->global_status)->toBe('classified_validated')
        ->and($document->display_status)->toBe('pending_review')
        ->and(DocumentAssignment::where('document_id', $document->document_id)->count())->toBe(0);

    expect(NotificationRecord::where('recipient_id', $document->originator_id)
        ->where('document_id', $document->document_id)
        ->exists())->toBeTrue();
});

it('does not hold for readability review when a category has too few staged samples (cold start)', function () {
    User::factory()->approver('Job Order')->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);

    // Zero staged samples -> readability check is skipped entirely, not
    // failed — garbled content still routes normally as long as the
    // OBJECTIVE checks (sections, word count) pass.
    $document = ingestWithText('Job Order', READABILITY_GARBLED_JOB_ORDER);

    expect($document->readability_review_status)->toBeNull()
        ->and($document->readability_score)->toBeNull()
        ->and($document->global_status)->toBe('classified_validated')
        ->and(DocumentAssignment::where('document_id', $document->document_id)->count())->toBe(1);
});

it('routes normally without any hold when readability clears the threshold', function () {
    stageVocabulary('Job Order');
    User::factory()->approver('Job Order')->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);

    $document = ingestWithText('Job Order', READABILITY_GOOD_JOB_ORDER);

    expect($document->readability_review_status)->toBeNull()
        ->and($document->readability_score)->toBeGreaterThan(0)
        ->and($document->global_status)->toBe('classified_validated')
        ->and(DocumentAssignment::where('document_id', $document->document_id)->count())->toBe(1);
});

it('lets an admin confirm a held document, staging it into the vocabulary and routing it', function () {
    stageVocabulary('Job Order');
    User::factory()->approver('Job Order')->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);

    $document = ingestWithText('Job Order', READABILITY_GARBLED_JOB_ORDER);
    $oldScore = $document->readability_score;
    $vocabBefore = MlStagingSample::where('category', 'Job Order')->count();

    $admin = readabilityAdmin();
    seedReviewTime($admin, $document);
    $this->actingAs($admin)
        ->post(route('admin.ml.review.readability', $document), ['action' => 'confirm'])
        ->assertSessionHas('status');

    $document->refresh();
    expect($document->readability_review_status)->toBe('confirmed')
        ->and($document->is_validated)->toBeTrue()
        ->and($document->global_status)->toBe('classified_validated')
        ->and($document->readability_score)->toBeGreaterThanOrEqual($oldScore)
        ->and(DocumentAssignment::where('document_id', $document->document_id)->count())->toBe(1)
        ->and(MlStagingSample::where('category', 'Job Order')->count())->toBe($vocabBefore + 1);
});

it('lets an admin reject a held document, marking it rejected without staging or routing it', function () {
    stageVocabulary('Job Order');

    $document = ingestWithText('Job Order', READABILITY_GARBLED_JOB_ORDER);
    $vocabBefore = MlStagingSample::where('category', 'Job Order')->count();

    $admin = readabilityAdmin();
    seedReviewTime($admin, $document);
    $this->actingAs($admin)
        ->post(route('admin.ml.review.readability', $document), ['action' => 'reject'])
        ->assertSessionHas('status');

    $document->refresh();
    expect($document->readability_review_status)->toBe('dismissed')
        ->and($document->global_status)->toBe('rejected')
        ->and(DocumentAssignment::where('document_id', $document->document_id)->count())->toBe(0)
        ->and(MlStagingSample::where('category', 'Job Order')->count())->toBe($vocabBefore);
});

it('rejects reviewing a document that is not pending readability review', function () {
    $document = DocumentRepository::create([
        'originator_id' => User::factory()->originator()->create()->user_id,
        'title' => 'not-pending.txt', 'file_path' => 'documents/not-pending.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'processing',
        'ml_category' => 'Job Order',
    ]);

    $this->actingAs(readabilityAdmin())
        ->post(route('admin.ml.review.readability', $document), ['action' => 'reject'])
        ->assertNotFound();
});

it('confirming readability review routes the document immediately — classification confidence is no longer a second gate', function () {
    // Regression: this used to require BOTH a classification-confidence
    // AND a readability confirm before routing. Classification review is
    // retired (see WorkflowService::ingest()'s $isAmbiguous docblock) —
    // readability is the only remaining hold, so confirming it now routes
    // on its own.
    User::factory()->approver('Job Order')->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);
    stageVocabulary('Job Order');

    $originator = User::factory()->originator()->create();
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'readability-only.txt', 'file_path' => 'documents/readability-only.txt',
        'mime_type' => 'text/plain', 'ocr_text' => READABILITY_GOOD_JOB_ORDER,
        'due_date' => now()->addDay(), 'global_status' => 'processing',
        'ml_category' => 'Job Order', 'ml_confidence' => 95.0,
        'readability_score' => 55, 'readability_review_status' => 'pending',
    ]);

    $admin = readabilityAdmin();

    seedReviewTime($admin, $document);
    $this->actingAs($admin)
        ->post(route('admin.ml.review.readability', $document), ['action' => 'confirm']);

    $document->refresh();
    expect($document->readability_review_status)->toBe('confirmed')
        ->and(DocumentAssignment::where('document_id', $document->document_id)->count())->toBe(1);
});

it('notifies every active admin when a document is held for readability review', function () {
    stageVocabulary('Job Order');
    $admin = User::factory()->admin()->create();

    $document = ingestWithText('Job Order', READABILITY_GARBLED_JOB_ORDER);

    expect($document->readability_review_status)->toBe('pending')
        ->and(NotificationRecord::where('recipient_id', $admin->user_id)
            ->where('document_id', $document->document_id)
            ->where('message_body', 'like', '%needs admin review%readability%')
            ->exists())->toBeTrue();
});

it('notifies the ORIGINATOR, not admins, when a document is rejected for an ambiguous classification', function () {
    // Confidence alone no longer holds a document for admin review — see
    // WorkflowService::ingest()'s $isAmbiguous docblock. Low confidence
    // AND a flat margin together are what reject it outright, straight
    // back to the originator to resubmit, with no admin involved.
    $admin = User::factory()->admin()->create();

    $mock = Mockery::mock(ClassificationService::class);
    $mock->shouldReceive('classify')->andReturn(['category' => 'Job Order', 'confidence' => 30.0, 'margin' => 5.0, 'model_id' => null]);
    $mock->shouldReceive('wordOverlapSimilarity')->andReturn(0.0);
    app()->instance(ClassificationService::class, $mock);

    $originator = User::factory()->originator()->create();
    $document = app(App\Services\WorkflowService::class)->ingest(
        UploadedFile::fake()->createWithContent('memo.txt', READABILITY_GOOD_JOB_ORDER),
        $originator,
        now()->addDay()->toDateTimeString(),
    );

    expect($document->global_status)->toBe('rejected')
        ->and(NotificationRecord::where('recipient_id', $originator->user_id)
            ->where('document_id', $document->document_id)
            ->where('message_body', 'like', "%doesn't clearly match any of our trained categories%")
            ->exists())->toBeTrue()
        ->and(NotificationRecord::where('recipient_id', $admin->user_id)
            ->where('document_id', $document->document_id)
            ->exists())->toBeFalse();
});

it('an ambiguous classification rejects the document immediately, taking priority over readability review', function () {
    // Both gates would have applied under the old design (garbled text +
    // low confidence) — ambiguity now short-circuits before readability
    // is ever considered, since routing an ambiguous document anywhere
    // (even one that also passed readability) risks the wrong approvers
    // deciding it — see ingest()'s branch ordering.
    stageVocabulary('Job Order');

    $mock = Mockery::mock(ClassificationService::class);
    $mock->shouldReceive('classify')->andReturn(['category' => 'Job Order', 'confidence' => 30.0, 'margin' => 5.0, 'model_id' => null]);
    $mock->shouldReceive('wordOverlapSimilarity')->andReturn(0.0);
    app()->instance(ClassificationService::class, $mock);

    $originator = User::factory()->originator()->create();
    $document = app(App\Services\WorkflowService::class)->ingest(
        UploadedFile::fake()->createWithContent('memo.txt', READABILITY_GARBLED_JOB_ORDER),
        $originator,
        now()->addDay()->toDateTimeString(),
    );

    expect($document->global_status)->toBe('rejected')
        ->and($document->readability_review_status)->not->toBe('pending');
});

it('shows the Content Readability Review panel on the ML training page', function () {
    stageVocabulary('Job Order');
    $document = ingestWithText('Job Order', READABILITY_GARBLED_JOB_ORDER);

    $this->actingAs(readabilityAdmin())
        ->get(route('admin.ml.training'))
        ->assertOk()
        ->assertSee('Content Readability Review')
        ->assertSee($document->title)
        ->assertSee($document->readability_score . '% readability', false);
});

it('shows the readability score on the originator tracking page once computed', function () {
    stageVocabulary('Job Order');
    User::factory()->approver('Job Order')->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);

    $document = ingestWithText('Job Order', READABILITY_GOOD_JOB_ORDER);
    expect($document->readability_score)->not->toBeNull();

    $response = $this->actingAs($document->originator)->get(route('originator.documents.show', $document));

    $response->assertOk()->assertSee("Readability: {$document->readability_score}%", false);
});

it('does not show a readability line when the category had too few staged samples to score at all', function () {
    User::factory()->approver('Job Order')->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);

    $document = ingestWithText('Job Order', READABILITY_GOOD_JOB_ORDER);
    expect($document->readability_score)->toBeNull();

    $response = $this->actingAs($document->originator)->get(route('originator.documents.show', $document));

    $response->assertOk()->assertDontSee('Readability:', false);
});

it('the poll endpoint reports readability-pending document ids for the live-refresh fallback', function () {
    stageVocabulary('Job Order');
    $document = ingestWithText('Job Order', READABILITY_GARBLED_JOB_ORDER);

    $this->actingAs(readabilityAdmin())
        ->getJson(route('admin.ml.review.poll'))
        ->assertOk()
        ->assertJson(['readability_pending_ids' => [$document->document_id]]);
});
