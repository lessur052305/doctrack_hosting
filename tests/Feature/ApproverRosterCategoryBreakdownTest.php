<?php
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\SlaViolation;
use App\Models\User;
use App\Models\WorkflowStage;

function documentInCategory(string $category, User $originator): DocumentRepository
{
    return DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => "{$category}-doc.txt",
        'file_path' => "documents/{$category}-doc.txt",
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
        'ml_category' => $category,
    ]);
}

function breachFor(User $approver, string $category, User $originator): void
{
    $document = documentInCategory($category, $originator);
    $stage = WorkflowStage::create(['document_category' => $category, 'stage_name' => 'Review', 'sequence_order' => 1]);
    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id,
        'user_id' => $approver->user_id,
        'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date,
        'priority_rank' => 2,
        'individual_status' => 'pending',
        'sla_expires_at' => now()->subMinutes(5),
    ]);
    SlaViolation::create([
        'document_id' => $assignment->document_id,
        'assignment_id' => $assignment->assignment_id,
        'approver_id' => $approver->user_id,
        'violation_timestamp' => now(),
        'duration_overdue' => 30,
        'stage_name' => 'Review',
    ]);
}

test('an approver reassigned between categories shows breach history split by category, not lumped into one total', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    // Currently assigned to Purchase Requisition, but has breach history from
    // back when they were in Job Order — simulating a category reassignment.
    $approver = User::factory()->approver('Purchase Requisition')->create(['full_name' => 'Reassigned Approver']);

    breachFor($approver, 'Job Order', $originator);
    breachFor($approver, 'Job Order', $originator);
    breachFor($approver, 'Purchase Requisition', $originator);

    $response = $this->actingAs($admin)->get(route('admin.sla.violations'));
    $response->assertOk();

    $byApproverCategory = $response->viewData('byApproverCategory')->get($approver->user_id);
    $totals = $byApproverCategory->pluck('total', 'ml_category');

    expect($totals['Job Order'])->toBe(2)
        ->and($totals['Purchase Requisition'])->toBe(1);

    $response->assertSee('Reassigned Approver');
    $response->assertSee('Job Order');
});
