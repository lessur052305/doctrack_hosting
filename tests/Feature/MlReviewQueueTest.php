<?php

use App\Events\DocumentStatusChanged;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\MlModelRepository;
use App\Models\MlStagingSample;
use App\Models\NotificationRecord;
use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Support\Facades\Event;

/**
 * Coverage for the low-confidence review queue. WorkflowService::process()
 * HOLDS a document below the confidence threshold — it does not route to
 * any approver — until AdminController::reviewFlaggedDocument() confirms
 * (routing it) or rejects it (sets global_status to 'rejected', reusing the
 * originator's existing resubmit flow). recheckFlaggedDocument() lets an
 * admin see how a confirmed document scores against the current model.
 */
function mlReviewAdmin(): User
{
    return User::factory()->admin()->create();
}

function flaggedDocument(float $confidence = 35.0, ?string $category = 'Job Order', string $text = 'some flagged sample text'): DocumentRepository
{
    $originator = User::factory()->originator()->create();

    return DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'flagged-' . uniqid() . '.txt',
        'file_path' => 'documents/' . uniqid() . '.txt',
        'mime_type' => 'text/plain',
        'ocr_text' => $text,
        'global_status' => 'classified_validated',
        'ml_category' => $category,
        'ml_confidence' => $confidence,
        'ml_review_status' => 'pending',
    ]);
}

it('flags a document for review and holds it (no assignment created) when confidence falls below the threshold', function () {
    config(['ml.review_confidence_threshold' => 50]);

    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);
    $document = flaggedDocument(confidence: 40.0, category: 'Job Order');

    expect($document->ml_review_status)->toBe('pending');
    expect(DocumentAssignment::where('document_id', $document->document_id)->count())->toBe(0);
    expect($document->display_status)->toBe('pending_review');
});

it('shows the review queue on the ML training page, with high-priority documents marked', function () {
    $lowest = flaggedDocument(confidence: 12.0, text: 'purchase requisition department item quantity budget approved');
    $moderate = flaggedDocument(confidence: 45.0, text: 'service report technician findings date of service completed');

    $this->actingAs(mlReviewAdmin())
        ->get(route('admin.ml.training'))
        ->assertOk()
        ->assertSee('Awaiting ML Review')
        ->assertSee('High priority')
        ->assertSee($lowest->title)
        ->assertSee($moderate->title);
});

// 19 unique non-stopword words after preprocessing — long enough that
// changing exactly one word lands the Jaccard similarity at 18/20 = 0.90,
// comfortably between NEAR_DUPLICATE_THRESHOLD (0.85, "worth grouping for
// display") and EXACT_DUPLICATE_THRESHOLD (0.97, "genuinely the same
// document, resolve together") — a near-duplicate, not an exact one.
const NEAR_DUP_BASE = 'job order requested by staff description work repair leaking pump conference room third floor building maintenance department ticket priority urgent';
const NEAR_DUP_VARIANT = 'job order requested by staff description work repair leaking pump conference room third floor building maintenance department ticket priority normal';

it('groups near-duplicate (but not identical) flagged documents behind an expandable disclosure, each still individually actionable', function () {
    $primary = flaggedDocument(confidence: 20.0, text: NEAR_DUP_BASE);
    $similar = flaggedDocument(confidence: 22.0, text: NEAR_DUP_VARIANT);

    $response = $this->actingAs(mlReviewAdmin())->get(route('admin.ml.training'));

    $response->assertOk()->assertSee('+1 similar document');
    $response->assertSee($primary->title);
    // Grouped-away documents still get their own reachable Confirm/Reject
    // action, not just a count — a bare "+1 similar" label with nothing
    // behind it would leave that document stuck at ml_review_status
    // ='pending' forever, since confirming the primary doesn't touch it.
    $response->assertSee($similar->title);
    $response->assertSee(route('admin.ml.review', $similar), false);
});

it('lets an admin confirm a near-duplicate (not identical) document independently, without touching its group\'s primary', function () {
    User::factory()->approver('Job Order')->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);

    $primary = flaggedDocument(confidence: 20.0, category: 'Job Order', text: NEAR_DUP_BASE);
    $similar = flaggedDocument(confidence: 22.0, category: 'Job Order', text: NEAR_DUP_VARIANT);

    $this->actingAs(mlReviewAdmin())
        ->post(route('admin.ml.review', $similar), ['action' => 'confirm', 'category' => 'Job Order'])
        ->assertSessionHas('status');

    expect($similar->fresh()->ml_review_status)->toBe('confirmed');
    expect(DocumentAssignment::where('document_id', $similar->document_id)->exists())->toBeTrue();
    // Near-duplicate, not exact — the primary must NOT be swept along.
    expect($primary->fresh()->ml_review_status)->toBe('pending');
});

it('confirming a document also confirms and routes a genuinely-identical (exact-duplicate) sibling, staging only the primary for training', function () {
    User::factory()->approver('Job Order')->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);

    $primary = flaggedDocument(confidence: 20.0, category: 'Job Order', text: NEAR_DUP_BASE);
    $exact = flaggedDocument(confidence: 22.0, category: 'Job Order', text: NEAR_DUP_BASE); // identical text -> similarity 1.0

    $this->actingAs(mlReviewAdmin())
        ->post(route('admin.ml.review', $primary), ['action' => 'confirm', 'category' => 'Job Order'])
        ->assertSessionHas('status');

    expect($primary->fresh()->ml_review_status)->toBe('confirmed');
    expect($exact->fresh()->ml_review_status)->toBe('confirmed');
    expect(DocumentAssignment::where('document_id', $primary->document_id)->exists())->toBeTrue();
    expect(DocumentAssignment::where('document_id', $exact->document_id)->exists())->toBeTrue();
    // Staging the identical text twice would just trip the near-duplicate
    // warning against itself for no benefit — only one sample expected.
    expect(MlStagingSample::where('category', 'Job Order')->count())->toBe(1);
});

it('rejecting a document also rejects a genuinely-identical (exact-duplicate) sibling', function () {
    $primary = flaggedDocument(confidence: 20.0, category: 'Job Order', text: NEAR_DUP_BASE);
    $exact = flaggedDocument(confidence: 22.0, category: 'Job Order', text: NEAR_DUP_BASE);

    $this->actingAs(mlReviewAdmin())
        ->post(route('admin.ml.review', $primary), ['action' => 'reject'])
        ->assertSessionHas('status');

    expect($primary->fresh()->global_status)->toBe('rejected');
    expect($exact->fresh()->global_status)->toBe('rejected');
    expect($exact->fresh()->ml_review_status)->toBe('dismissed');
});

it('shows exact duplicates as an informational note rather than a separately-actionable row', function () {
    $primary = flaggedDocument(confidence: 20.0, text: NEAR_DUP_BASE);
    $exact = flaggedDocument(confidence: 22.0, text: NEAR_DUP_BASE);

    $response = $this->actingAs(mlReviewAdmin())->get(route('admin.ml.training'));

    $response->assertOk()
        ->assertSee('Will also resolve another identical document')
        ->assertSee($exact->title)
        // No separate Confirm/Reject route for the exact-duplicate sibling —
        // acting on the primary is the only way to resolve it.
        ->assertDontSee(route('admin.ml.review', $exact), false);
});

it('confirming a category routes the held document to an eligible approver and always stages it for training', function () {
    $approver = User::factory()->approver('Purchase Requisition')->create();
    WorkflowStage::create(['document_category' => 'Purchase Requisition', 'stage_name' => 'Review', 'sequence_order' => 1]);

    $document = flaggedDocument(confidence: 30.0, category: 'Job Order', text: 'a job order sample');

    $this->actingAs(mlReviewAdmin())
        ->post(route('admin.ml.review', $document), [
            'action' => 'confirm',
            'category' => 'Purchase Requisition',
        ])
        ->assertSessionHas('status');

    $document->refresh();
    expect($document->ml_review_status)->toBe('confirmed');
    expect($document->ml_category)->toBe('Purchase Requisition');
    expect($document->display_status)->not->toBe('pending_review'); // held state cleared
    expect(DocumentAssignment::where('document_id', $document->document_id)->where('user_id', $approver->user_id)->exists())->toBeTrue();
    expect(MlStagingSample::where('category', 'Purchase Requisition')->where('extracted_text', 'a job order sample')->exists())->toBeTrue();
});

it('rejecting a held document marks it rejected, notifies the originator, and does not route or stage it', function () {
    $document = flaggedDocument();

    $this->actingAs(mlReviewAdmin())
        ->post(route('admin.ml.review', $document), ['action' => 'reject'])
        ->assertSessionHas('status');

    $document->refresh();
    expect($document->ml_review_status)->toBe('dismissed');
    expect($document->global_status)->toBe('rejected'); // reuses the existing resubmit precondition
    expect(DocumentAssignment::where('document_id', $document->document_id)->count())->toBe(0);
    expect(MlStagingSample::count())->toBe(0);
    expect(NotificationRecord::where('recipient_id', $document->originator_id)->where('document_id', $document->document_id)->exists())->toBeTrue();
});

it('rejects reviewing a document that is not pending review', function () {
    $document = flaggedDocument();
    $document->update(['ml_review_status' => 'dismissed']);

    $this->actingAs(mlReviewAdmin())
        ->post(route('admin.ml.review', $document), ['action' => 'reject'])
        ->assertNotFound();
});

it('re-checking a confirmed document records a fresh classification result without touching the original', function () {
    $document = flaggedDocument(confidence: 30.0);
    $document->update(['ml_review_status' => 'confirmed']); // confirmed_at_model_id stays null
    MlModelRepository::create(['model_name' => 'SVM', 'version' => 'v2', 'is_active' => true]); // a model now exists -> "retrained since confirm"

    $this->actingAs(mlReviewAdmin())
        ->post(route('admin.ml.review.recheck', $document))
        ->assertSessionHas('status');

    $document->refresh();
    expect($document->ml_rechecked_at)->not->toBeNull();
    expect((float) $document->ml_confidence)->toBe(30.0); // original untouched
});

it('broadcasts DocumentStatusChanged when a document is re-checked', function () {
    $document = flaggedDocument(confidence: 30.0);
    $document->update(['ml_review_status' => 'confirmed']);
    MlModelRepository::create(['model_name' => 'SVM', 'version' => 'v2', 'is_active' => true]);

    Event::fake([DocumentStatusChanged::class]);

    $this->actingAs(mlReviewAdmin())->post(route('admin.ml.review.recheck', $document));

    Event::assertDispatched(DocumentStatusChanged::class, fn ($e) => $e->document->document_id === $document->document_id);
});

it('rejects re-checking a document that has not been retrained since it was confirmed', function () {
    $document = flaggedDocument(confidence: 30.0);
    $document->update(['ml_review_status' => 'confirmed']); // no model ever trained -> activeModelId is null

    $this->actingAs(mlReviewAdmin())
        ->post(route('admin.ml.review.recheck', $document))
        ->assertStatus(409);

    expect($document->fresh()->ml_rechecked_at)->toBeNull();
});

it('rejects re-checking a document whose confirmed model is still the active one', function () {
    $model = MlModelRepository::create(['model_name' => 'SVM', 'version' => 'v1', 'is_active' => true]);
    $document = flaggedDocument(confidence: 30.0);
    $document->update(['ml_review_status' => 'confirmed', 'confirmed_at_model_id' => $model->model_id]);

    $this->actingAs(mlReviewAdmin())
        ->post(route('admin.ml.review.recheck', $document))
        ->assertStatus(409);
});

it('shows "Waiting for the next retrain" instead of a Re-check button until the model actually changes', function () {
    $model = MlModelRepository::create(['model_name' => 'SVM', 'version' => 'v1', 'is_active' => true]);
    $document = flaggedDocument(confidence: 30.0);
    $document->update(['ml_review_status' => 'confirmed', 'confirmed_at_model_id' => $model->model_id]);

    $this->actingAs(mlReviewAdmin())
        ->get(route('admin.ml.training'))
        ->assertOk()
        ->assertSee('Waiting for the next retrain')
        ->assertDontSee(route('admin.ml.review.recheck', $document), false);
});

it('does not show a dismiss button for a confirmed document that has not been re-checked yet', function () {
    $document = flaggedDocument(confidence: 30.0);
    $document->update(['ml_review_status' => 'confirmed']);

    $this->actingAs(mlReviewAdmin())
        ->get(route('admin.ml.training'))
        ->assertOk()
        ->assertDontSee(route('admin.ml.review.dismiss', $document), false);
});

it('shows a dismiss button once a confirmed document has been re-checked', function () {
    $document = flaggedDocument(confidence: 30.0);
    $document->update(['ml_review_status' => 'confirmed', 'ml_rechecked_at' => now(), 'ml_recheck_category' => 'Job Order', 'ml_recheck_confidence' => 90.0]);

    $this->actingAs(mlReviewAdmin())
        ->get(route('admin.ml.training'))
        ->assertOk()
        ->assertSee(route('admin.ml.review.dismiss', $document), false);
});

it('dismissing a re-checked document removes it from the Confirmed From Review list and broadcasts the change', function () {
    $document = flaggedDocument(confidence: 30.0);
    $document->update(['ml_review_status' => 'confirmed', 'ml_rechecked_at' => now(), 'ml_recheck_category' => 'Job Order', 'ml_recheck_confidence' => 90.0]);

    Event::fake([DocumentStatusChanged::class]);

    $this->actingAs(mlReviewAdmin())
        ->post(route('admin.ml.review.dismiss', $document))
        ->assertSessionHas('status');

    expect($document->fresh()->ml_recheck_dismissed_at)->not->toBeNull();
    Event::assertDispatched(DocumentStatusChanged::class, fn ($e) => $e->document->document_id === $document->document_id);

    // Checking the row's own "View File" link rather than the bare title —
    // the redirect's own flash message ("Dismissed 'title'...") also
    // contains the title, which would make a plain assertDontSee($title)
    // a false failure unrelated to whether the row itself is still listed.
    $this->actingAs(mlReviewAdmin())
        ->get(route('admin.ml.training'))
        ->assertDontSee(route('documents.file', $document), false);
});

it('rejects dismissing a document that has not been re-checked yet', function () {
    $document = flaggedDocument(confidence: 30.0);
    $document->update(['ml_review_status' => 'confirmed']);

    $this->actingAs(mlReviewAdmin())
        ->post(route('admin.ml.review.dismiss', $document))
        ->assertNotFound();
});

it('shows the recheck confidence transition on the tracking page instead of the plain original confidence', function () {
    $document = flaggedDocument(confidence: 30.0);
    $document->update([
        'ml_review_status' => 'confirmed',
        'ml_recheck_category' => $document->ml_category,
        'ml_recheck_confidence' => 92.5,
        'ml_rechecked_at' => now(),
    ]);

    $response = $this->actingAs($document->originator)->get(route('originator.documents.show', $document));

    $response->assertOk()
        ->assertSee('Recheck Confidence: 30% &rarr; 92.5%', false)
        // The old plain-confidence line is a DIFFERENT markup shape
        // (no wrapping <span>, no "Recheck" prefix) — check for its exact
        // lead-in rather than a bare "Confidence: 30%" substring, since
        // that substring is also (correctly) present inside the "Recheck
        // Confidence: 30% -> ..." line above.
        ->assertDontSee('&middot; Confidence:', false);
});

it('shows the plain confidence line when a document has never been re-checked', function () {
    $document = flaggedDocument(confidence: 30.0);

    $response = $this->actingAs($document->originator)->get(route('originator.documents.show', $document));

    $response->assertOk()->assertSee('Confidence: 30%', false);
});

it('rejects re-checking a document that has not been confirmed yet', function () {
    $document = flaggedDocument(); // still 'pending'

    $this->actingAs(mlReviewAdmin())
        ->post(route('admin.ml.review.recheck', $document))
        ->assertNotFound();
});

it('shows a View File button that opens the original upload for a queued document', function () {
    $document = flaggedDocument();

    $this->actingAs(mlReviewAdmin())
        ->get(route('admin.ml.training'))
        ->assertOk()
        ->assertSee('View File')
        ->assertSee(route('documents.file', $document), false);
});

it('the refresh endpoint returns only the panels fragment, not a full page', function () {
    $document = flaggedDocument();

    $response = $this->actingAs(mlReviewAdmin())->get(route('admin.ml.review.refresh'));

    $response->assertOk()->assertSee($document->title);
    $response->assertDontSee('<html', false);
});

it('the poll endpoint reports the pending document ids for the live-refresh fallback', function () {
    $document = flaggedDocument();

    $this->actingAs(mlReviewAdmin())
        ->getJson(route('admin.ml.review.poll'))
        ->assertOk()
        ->assertJson(['pending_ids' => [$document->document_id]]);
});

it('broadcasts DocumentStatusChanged when WorkflowService::ingest() holds a newly-uploaded low-confidence document', function () {
    config(['ml.review_confidence_threshold' => 50]);
    Event::fake([DocumentStatusChanged::class]);

    // Mocked rather than run through the real OCR/SVM pipeline (same
    // reasoning WorkflowRoutingTest documents: these tests exercise
    // ingest()'s own control flow, not the ML pipeline itself).
    $this->mock(App\Services\TextExtractionService::class, function ($mock) {
        $mock->shouldReceive('extract')->andReturn(['text' => str_repeat('word ', 40), 'used_ocr_fallback' => false, 'failure_reason' => null]);
    });
    $this->mock(App\Services\ClassificationService::class, function ($mock) {
        $mock->shouldReceive('classify')->andReturn(['category' => 'Purchase Requisition', 'confidence' => 30.0, 'model_id' => null]);
    });
    $this->mock(App\Services\ValidationService::class, function ($mock) {
        $mock->shouldReceive('validate')->andReturn(['is_valid' => true, 'errors' => []]);
    });

    $originator = User::factory()->originator()->create();
    $file = \Illuminate\Http\UploadedFile::fake()->create('sample.txt', 10, 'text/plain');

    $document = app(App\Services\WorkflowService::class)->ingest($file, $originator, now()->addDay()->toDateTimeString());

    expect($document->ml_review_status)->toBe('pending');
    expect(DocumentAssignment::where('document_id', $document->document_id)->count())->toBe(0);
    Event::assertDispatched(DocumentStatusChanged::class, fn ($e) => $e->document->document_id === $document->document_id);
});

it('broadcasts DocumentStatusChanged when a document is confirmed out of the review queue', function () {
    User::factory()->approver('Job Order')->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);

    $document = flaggedDocument(confidence: 30.0, category: 'Job Order');

    Event::fake([DocumentStatusChanged::class]);

    $this->actingAs(mlReviewAdmin())
        ->post(route('admin.ml.review', $document), ['action' => 'confirm', 'category' => 'Job Order']);

    Event::assertDispatched(DocumentStatusChanged::class, fn ($e) => $e->document->document_id === $document->document_id);
});
