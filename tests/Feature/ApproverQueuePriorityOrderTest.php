<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use Carbon\Carbon;

beforeEach(function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
});

function priorityQueueDoc(User $approver, string $title, array $overrides = []): DocumentAssignment
{
    $originator = User::factory()->originator()->create();
    $stage = WorkflowStage::first();
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => $title, 'file_path' => 'documents/'.uniqid().'.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addDays(5), 'global_status' => 'classified_validated',
    ]);

    return DocumentAssignment::create(array_merge([
        'document_id' => $document->document_id, 'stage_id' => $stage->stage_id, 'user_id' => $approver->user_id,
        'individual_status' => 'pending', 'sla_expires_at' => now()->addHours(48), 'priority_rank' => 2,
        'auto_approved' => false,
    ], $overrides));
}

it('lists Urgent, then Normal, then Low — driven by real remaining business time, not due_date', function () {
    // Wednesday, mid-morning — safely inside a single working day so
    // small minute/hour offsets below don't accidentally cross into a
    // non-working period and skew the real-remaining math.
    $this->travelTo(Carbon::parse('2026-08-12 10:00:00'));
    $approver = User::factory()->approver('Job Order')->create();

    // Deliberately created out of urgency order. urgencyRank() no longer
    // reads priority_rank at all — it's driven entirely by real business
    // seconds left before sla_expires_at (Urgent <=30m, Normal <=2h, Low
    // beyond that). No "Expired" case here anymore: the Approver
    // dashboard already proactively escalates the VIEWING approver's own
    // expired assignments on load (ApprovalController — so they can
    // never act on a stale one), and a real approver miss now
    // auto-approves immediately instead of just flagging it (see
    // SlaService::autoApproveMissedDeadline()) — so an item that's already past its own
    // deadline can no longer sit visibly "Expired" in its own owner's
    // queue; it resolves the moment they load the page. Covered instead
    // in SlaEscalationTest.
    priorityQueueDoc($approver, 'low-doc.txt', ['sla_expires_at' => now()->addHours(4)]);
    priorityQueueDoc($approver, 'normal-doc.txt', ['sla_expires_at' => now()->addHour()]);
    priorityQueueDoc($approver, 'urgent-doc.txt', ['sla_expires_at' => now()->addMinutes(20)]);

    $response = $this->actingAs($approver)->get(route('approver.dashboard'));

    $response->assertOk();
    $response->assertSeeInOrder(['urgent-doc.txt', 'normal-doc.txt', 'low-doc.txt']);
});

it('sorts a container with nothing left to act on after every real priority', function () {
    $this->travelTo(Carbon::parse('2026-08-12 10:00:00'));
    $approver = User::factory()->approver('Job Order')->create();

    priorityQueueDoc($approver, 'low-doc.txt', ['sla_expires_at' => now()->addHours(4)]);

    // Already decided by this approver, still waiting on a co-approver on
    // the same stage — see ApprovalController::resolvedButInFlightQueryFor().
    $originator = User::factory()->originator()->create();
    $stage = WorkflowStage::first();
    $coApprover = User::factory()->approver('Job Order')->create();
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'waiting-on-others.txt', 'file_path' => 'documents/'.uniqid().'.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addDays(5), 'global_status' => 'classified_validated',
    ]);
    DocumentAssignment::create([
        'document_id' => $document->document_id, 'stage_id' => $stage->stage_id, 'user_id' => $approver->user_id,
        'individual_status' => 'approved', 'sla_expires_at' => now()->addHours(48), 'priority_rank' => 1,
        'auto_approved' => false, 'acted_at' => now(),
    ]);
    DocumentAssignment::create([
        'document_id' => $document->document_id, 'stage_id' => $stage->stage_id, 'user_id' => $coApprover->user_id,
        'individual_status' => 'pending', 'sla_expires_at' => now()->addHours(48), 'priority_rank' => 1,
        'auto_approved' => false,
    ]);

    $response = $this->actingAs($approver)->get(route('approver.dashboard'));

    $response->assertOk();
    $response->assertSeeInOrder(['low-doc.txt', 'waiting-on-others.txt']);
});
