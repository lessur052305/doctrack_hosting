<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;

beforeEach(function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
});

function assignmentFor(User $approver): DocumentAssignment
{
    $originator = User::factory()->originator()->create();
    $stage = WorkflowStage::first();
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'doc.txt', 'file_path' => 'documents/doc.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addHours(2), 'global_status' => 'classified_validated',
    ]);

    return DocumentAssignment::create([
        'document_id' => $document->document_id, 'stage_id' => $stage->stage_id, 'user_id' => $approver->user_id,
        'individual_status' => 'pending', 'sla_expires_at' => now()->addHour(), 'priority_rank' => 1,
        'escalated_to_admin' => false, 'auto_approved' => false,
    ]);
}

it('reports the current pending count for the approver', function () {
    $approver = User::factory()->approver('Job Order')->create();
    assignmentFor($approver);
    assignmentFor($approver);

    $response = $this->actingAs($approver)->getJson(route('approver.assignments.poll'));

    $response->assertOk()->assertJson(['pending_count' => 2]);
});

it('does not count another approver\'s pending assignments', function () {
    $approver = User::factory()->approver('Job Order')->create();
    $otherApprover = User::factory()->approver('Job Order')->create();
    assignmentFor($otherApprover);

    $response = $this->actingAs($approver)->getJson(route('approver.assignments.poll'));

    $response->assertOk()->assertJson(['pending_count' => 0]);
});

it('reflects a newly routed assignment on the next poll', function () {
    $approver = User::factory()->approver('Job Order')->create();

    $this->actingAs($approver)->getJson(route('approver.assignments.poll'))
        ->assertJson(['pending_count' => 0]);

    assignmentFor($approver);

    $this->actingAs($approver)->getJson(route('approver.assignments.poll'))
        ->assertJson(['pending_count' => 1]);
});

it('rejects poll requests from a non-approver', function () {
    $originator = User::factory()->originator()->create();

    $this->actingAs($originator)->getJson(route('approver.assignments.poll'))->assertForbidden();
});

it('passes the initial pending count to the dashboard for the polling JS baseline', function () {
    $approver = User::factory()->approver('Job Order')->create();
    assignmentFor($approver);

    $response = $this->actingAs($approver)->get(route('approver.dashboard'));

    $response->assertOk();
    $response->assertSee('data-initial-count="1"', false);
});

it('renders the queue fragment reflecting a newly routed assignment', function () {
    $approver = User::factory()->approver('Job Order')->create();

    $this->actingAs($approver)->get(route('approver.assignments.refresh'))
        ->assertSee('Your queue is clear');

    assignmentFor($approver);

    $this->actingAs($approver)->get(route('approver.assignments.refresh'))
        ->assertOk()
        ->assertSee('doc.txt')
        ->assertDontSee('Your queue is clear');
});

it('respects the priority filter on the refresh fragment, same as a full page load', function () {
    $approver = User::factory()->approver('Job Order')->create();
    assignmentFor($approver); // priority_rank 1 = Urgent

    $this->actingAs($approver)->get(route('approver.assignments.refresh', ['priority' => 'Low']))
        ->assertSee('No pending documents match these filters');
});

it('rejects refresh requests from a non-approver', function () {
    $originator = User::factory()->originator()->create();

    $this->actingAs($originator)->get(route('approver.assignments.refresh'))->assertForbidden();
});
