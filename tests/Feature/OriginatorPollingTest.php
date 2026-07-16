<?php

use App\Models\DocumentRepository;
use App\Models\User;

function docFor(User $originator, array $overrides = []): DocumentRepository
{
    return DocumentRepository::create(array_merge([
        'originator_id' => $originator->user_id, 'title' => 'doc.txt', 'file_path' => 'documents/doc.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addHours(2), 'global_status' => 'classified_validated',
    ], $overrides));
}

it('reports the current document count and latest update timestamp', function () {
    $originator = User::factory()->originator()->create();
    docFor($originator);

    $response = $this->actingAs($originator)->getJson(route('originator.documents.poll'));

    $response->assertOk()->assertJsonStructure(['count', 'latest_update']);
    expect($response->json('count'))->toBe(1);
});

it('does not count another originator\'s documents', function () {
    $mine = User::factory()->originator()->create();
    $someoneElse = User::factory()->originator()->create();
    docFor($someoneElse);

    $response = $this->actingAs($mine)->getJson(route('originator.documents.poll'));

    $response->assertOk();
    expect($response->json('count'))->toBe(0);
});

it('changes the signal when an existing document\'s status changes, not just on count changes', function () {
    $originator = User::factory()->originator()->create();
    $doc = docFor($originator);

    $before = $this->actingAs($originator)->getJson(route('originator.documents.poll'))->json('latest_update');

    // Simulate a real status transition (approval), not a raw ->update() —
    // touches updated_at exactly like the real WorkflowService would.
    $this->travel(1)->minute();
    $doc->update(['global_status' => 'approved']);

    $after = $this->actingAs($originator)->getJson(route('originator.documents.poll'))->json('latest_update');

    expect($after)->not->toBe($before);
});

it('renders the submissions fragment reflecting a newly uploaded document', function () {
    $originator = User::factory()->originator()->create();

    $this->actingAs($originator)->get(route('originator.documents.refresh'))
        ->assertSee('No documents submitted yet');

    docFor($originator);

    $this->actingAs($originator)->get(route('originator.documents.refresh'))
        ->assertOk()
        ->assertSee('doc.txt')
        ->assertDontSee('No documents submitted yet');
});

it('respects the status filter on the refresh fragment, same as a full page load', function () {
    $originator = User::factory()->originator()->create();
    docFor($originator, ['global_status' => 'classified_validated']);

    $this->actingAs($originator)->get(route('originator.documents.refresh', ['status' => 'rejected']))
        ->assertSee('No documents match these filters');
});

it('rejects poll/refresh requests from a non-originator', function () {
    $approver = User::factory()->approver('Job Order')->create();

    $this->actingAs($approver)->getJson(route('originator.documents.poll'))->assertForbidden();
    $this->actingAs($approver)->get(route('originator.documents.refresh'))->assertForbidden();
});
