<?php

use App\Models\DocumentRepository;
use App\Models\User;

function approvedUnrelatedDoc(User $originator): DocumentRepository
{
    return DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'weird-memo.txt', 'file_path' => 'documents/weird.txt', 'mime_type' => 'text/plain',
        'ml_category' => 'Job Order', // classifier's best guess — kept, but not authoritative
        'is_validated' => true, 'due_date' => now()->addDay(),
        'global_status' => 'approved', 'desired_routing' => 'unrelated', 'custom_routed' => true,
    ]);
}

test('the Other folder appears once an unrelated document exists, and is scoped to desired_routing not ml_category', function () {
    $originator = User::factory()->originator()->create();
    $doc = approvedUnrelatedDoc($originator);
    // A real Job Order, for contrast — should NOT show up in the
    // Other folder even though it shares the same ml_category.
    DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'real-job-order.txt', 'file_path' => 'documents/real.txt', 'mime_type' => 'text/plain',
        'ml_category' => 'Job Order', 'is_validated' => true, 'due_date' => now()->addDay(),
        'global_status' => 'approved', 'desired_routing' => 'auto',
    ]);

    $folders = $this->actingAs($originator)->get(route('originator.archive'));
    $folders->assertOk()->assertSee('Other');

    $response = $this->actingAs($originator)->get(route('originator.archive', ['category' => 'Other']));
    $response->assertOk()->assertSee('weird-memo.txt')->assertDontSee('real-job-order.txt');
});

test('the Other folder does not appear when there is nothing in it', function () {
    $originator = User::factory()->originator()->create();
    DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'real-job-order.txt', 'file_path' => 'documents/real.txt', 'mime_type' => 'text/plain',
        'ml_category' => 'Job Order', 'is_validated' => true, 'due_date' => now()->addDay(),
        'global_status' => 'approved', 'desired_routing' => 'auto',
    ]);

    $response = $this->actingAs($originator)->get(route('originator.archive'));

    $response->assertOk()->assertDontSee('Other');
});
