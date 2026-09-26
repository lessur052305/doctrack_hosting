<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageDepartment;
use Illuminate\Support\Facades\Route;

/**
 * The Approval Workflow page is a read-only view of UJF's fixed approval
 * stages — stages, their owning departments, and the seats pending on them.
 * There is nothing on it to add, rename, reorder, archive, delete, or
 * override.
 */
beforeEach(function () {
    $this->technical = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1, 'description' => 'Technical Review for Job Order documents.']);
    $this->final = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Final Approval', 'sequence_order' => 2]);
    WorkflowStageDepartment::create(['stage_id' => $this->technical->stage_id, 'department' => 'Engineering']);
    WorkflowStageDepartment::create(['stage_id' => $this->final->stage_id, 'department' => 'Engineering']);
    WorkflowStageDepartment::create(['stage_id' => $this->final->stage_id, 'department' => 'Finance']);
    $this->admin = User::factory()->admin()->create();
});

it('shows every stage with its owning departments, and marks Final Approval as the Head Approver sign-off', function () {
    $this->actingAs($this->admin)->get(route('admin.workflow.config'))
        ->assertOk()
        ->assertSee('Approval Workflow')
        ->assertSee('Technical Review')
        ->assertSee('Final Approval')
        ->assertSee('Head Approver sign-off')
        ->assertSee('Engineering')
        ->assertSee('Finance')
        ->assertSee('opens after every other stage is approved');
});

it('offers nothing that changes a stage: no add form, edit, reorder, archive, delete, or override', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'pending.txt', 'file_path' => 'documents/pending.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addDay(), 'global_status' => 'classified_validated',
    ]);
    DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $this->technical->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending', 'sla_expires_at' => now()->addHours(3),
    ]);

    $this->actingAs($this->admin)->get(route('admin.workflow.config'))
        ->assertOk()
        ->assertSee('1 pending assignment(s)')
        ->assertSee('pending.txt')
        ->assertDontSee('Add Workflow Stage')
        ->assertDontSee('Add Stage')
        ->assertDontSee('>Edit<', false)
        ->assertDontSee('>Archive</button>', false)
        ->assertDontSee('>Delete</button>', false)
        ->assertDontSee('Move up')
        ->assertDontSee('Review &amp; decide', false)
        ->assertDontSee('name="decision"', false);
});

it('no longer has any route for changing stages or overriding a decision', function () {
    foreach ([
        'admin.workflow.store', 'admin.workflow.stages.update', 'admin.workflow.stages.moveUp', 'admin.workflow.stages.moveDown',
        'admin.workflow.stages.notifyPending', 'admin.workflow.stages.archive', 'admin.workflow.stages.unarchive',
        'admin.workflow.stages.destroy', 'admin.sla.override',
    ] as $name) {
        expect(Route::has($name))->toBeFalse("route {$name} should be gone");
    }
});

it('is reachable from the Admin nav as Approval Workflow, at an /approval-workflow URL', function () {
    expect(route('admin.workflow.config', [], false))->toBe('/admin/approval-workflow');

    $this->actingAs($this->admin)->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Approval Workflow')
        ->assertDontSee('Workflow Config');
});

it('is still admin-only', function () {
    $approver = User::factory()->approver('Job Order')->create();

    $this->actingAs($approver)->get(route('admin.workflow.config'))->assertForbidden();
});

it('serves the live-refresh fragment with the same read-only stage list', function () {
    $this->actingAs($this->admin)->get(route('admin.workflow.config.refresh'))
        ->assertOk()
        ->assertSee('Technical Review')
        ->assertDontSee('Add Stage');
});
