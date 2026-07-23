<?php

use App\Models\DocumentRepository;
use App\Models\User;

/**
 * Regression coverage for lifecycle-stepper.blade.php's completion check.
 * $i < $currentIndex can never be true for the LAST step (nothing comes
 * after it to push the index further), so the "Approved" node stayed stuck
 * rendering as "in progress" (amber, numbered) forever, even once a
 * document was genuinely done — only the separate status badge above it
 * was ever actually turning green.
 */
function steppedDocument(string $globalStatus): DocumentRepository
{
    $originator = User::factory()->originator()->create();

    return DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'stepper-' . uniqid() . '.txt',
        'file_path' => 'documents/' . uniqid() . '.txt',
        'mime_type' => 'text/plain',
        'ml_category' => 'Job Order',
        'global_status' => $globalStatus,
    ]);
}

it('renders the final Approved step as complete (green) once a document is actually approved', function () {
    $document = steppedDocument('approved');

    $html = $this->actingAs($document->originator)->get(route('originator.documents.show', $document))->getContent();

    // All three steps (Submitted, Classified & Validated, Approved) should
    // render complete/green — the stale bug left the amber "current"
    // (processing-gradient) styling on the last step forever, even once
    // the document was genuinely done.
    expect(substr_count($html, 'from-approved-500 to-approved-600'))->toBe(3);
    expect($html)->not->toContain('from-processing-500 to-processing-600');
});

it('renders both completed nodes green and the line toward the pending step amber, once classified and validated', function () {
    $document = steppedDocument('classified_validated');

    $html = $this->actingAs($document->originator)->get(route('originator.documents.show', $document))->getContent();

    // "Classified & Validated" is a completed fact by this point, same as
    // "Submitted" — it should check green like any other reached node, not
    // sit amber as if it were still happening. The "processing" signal
    // belongs on the connecting line leading toward "Approved" instead,
    // since that's the part that's actually still pending.
    expect(substr_count($html, 'from-approved-500 to-approved-600'))->toBe(2); // Submitted + Classified & Validated
    expect($html)->toContain('bg-processing-500'); // the line toward the still-pending Approved step
});

it('renders the line toward Classified & Validated as processing while a document is still being classified', function () {
    $document = steppedDocument('processing');

    $html = $this->actingAs($document->originator)->get(route('originator.documents.show', $document))->getContent();

    expect(substr_count($html, 'from-approved-500 to-approved-600'))->toBe(1); // Submitted only
    expect($html)->toContain('bg-processing-500');
});
