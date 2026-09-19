<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\WorkflowService;

beforeEach(function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Final Approval', 'sequence_order' => 2]);
});

/**
 * A pre-classified, pre-validated document — these tests exercise routing,
 * load balancing, and decision handling, not classification/validation
 * (see ValidationServiceTest and the classifier's own coverage for that),
 * so there's no need to run real text through the ML pipeline here.
 */
function classifiedJobOrder(User $originator): DocumentRepository
{
    return DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'job_order.txt',
        'file_path' => 'documents/job_order.txt',
        'mime_type' => 'text/plain',
        'ml_category' => 'Job Order',
        'is_validated' => true,
        'due_date' => now()->addMinutes(30),
        'global_status' => 'classified_validated',
    ]);
}

test('a validated document is routed to every configured stage for its category', function () {
    $originator = User::factory()->originator()->create();
    User::factory()->approver('Job Order')->create();
    $workflow = app(WorkflowService::class);

    $document = classifiedJobOrder($originator);
    $workflow->routeToWorkflow($document);

    expect(DocumentAssignment::where('document_id', $document->document_id)->count())->toBe(2);
});

test('every stage of a document shares the exact same SLA deadline, computed once for the whole document', function () {
    $originator = User::factory()->originator()->create();
    User::factory()->approver('Job Order')->create();
    $workflow = app(WorkflowService::class);

    $document = classifiedJobOrder($originator);
    $workflow->routeToWorkflow($document);

    // Two stages (Technical Review, Final Approval) — every assignment
    // across BOTH must carry the identical deadline, guaranteed by a
    // single up-front computation rather than each stage separately
    // calling now() a few milliseconds apart (see routeToWorkflow()).
    $deadlines = DocumentAssignment::where('document_id', $document->document_id)->pluck('sla_expires_at');

    expect($deadlines)->toHaveCount(2)
        ->and($deadlines->unique())->toHaveCount(1);
});

test('a stage is routed to every eligible approver simultaneously, not just one', function () {
    $originator = User::factory()->originator()->create();
    $approverA = User::factory()->approver('Job Order')->create();
    $approverB = User::factory()->approver('Job Order')->create();
    $approverC = User::factory()->approver('Job Order')->create();
    $workflow = app(WorkflowService::class);

    $document = classifiedJobOrder($originator);
    $workflow->routeToWorkflow($document);

    $stage = WorkflowStage::where('stage_name', 'Technical Review')->first();
    $seats = DocumentAssignment::where('document_id', $document->document_id)
        ->where('stage_id', $stage->stage_id)
        ->get();

    expect($seats->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$approverA->user_id, $approverB->user_id, $approverC->user_id])->sort()->values()->all())
        ->and($seats->pluck('sla_expires_at')->unique())->toHaveCount(1); // identical window for every seat
});

test('a stage does not finalize the document until every seat has approved, and finalizes exactly once the last one does', function () {
    $originator = User::factory()->originator()->create();
    $approverA = User::factory()->approver('Job Order')->create();
    $approverB = User::factory()->approver('Job Order')->create();
    $approverC = User::factory()->approver('Job Order')->create();
    $workflow = app(WorkflowService::class);

    // Single-stage category so there's nothing else that could independently
    // keep the document from finalizing — isolates the stage-scoped gate.
    WorkflowStage::create(['document_category' => 'Solo Stage Category', 'stage_name' => 'Only Stage', 'sequence_order' => 1]);
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'solo.txt', 'file_path' => 'documents/solo.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Solo Stage Category', 'is_validated' => true,
        'due_date' => now()->addMinutes(30), 'global_status' => 'classified_validated',
    ]);
    $approverA->update(['assigned_category' => 'Solo Stage Category']);
    $approverB->update(['assigned_category' => 'Solo Stage Category']);
    $approverC->update(['assigned_category' => 'Solo Stage Category']);
    $workflow->routeToWorkflow($document);

    $seats = DocumentAssignment::where('document_id', $document->document_id)->orderBy('user_id')->get();
    expect($seats)->toHaveCount(3);

    $workflow->decide($seats[0], $approverA, 'approved');
    expect($document->fresh()->global_status)->toBe('classified_validated'); // 1 of 3 — not done

    $workflow->decide($seats[1], $approverB, 'approved');
    expect($document->fresh()->global_status)->toBe('classified_validated'); // 2 of 3 — still not done

    $workflow->decide($seats[2], $approverC, 'approved');
    expect($document->fresh()->global_status)->toBe('approved'); // 3rd and last — now it's done
});

test('the safety-net stage-assignment path waits for every sibling on the CURRENT stage before opening a stage added after routing', function () {
    $originator = User::factory()->originator()->create();
    $approverA = User::factory()->approver('Job Order')->create();
    $approverB = User::factory()->approver('Job Order')->create();
    $workflow = app(WorkflowService::class);

    // Only one stage exists at routing time.
    WorkflowStage::query()->where('stage_name', 'Final Approval')->delete();
    $document = classifiedJobOrder($originator);
    $workflow->routeToWorkflow($document);

    // Admin adds a new stage to the pipeline AFTER this document was
    // already routed — completeStage()'s safety net is what assigns it.
    $lateStage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Late-Added Review', 'sequence_order' => 2]);

    $seats = DocumentAssignment::where('document_id', $document->document_id)->orderBy('user_id')->get();
    expect($seats)->toHaveCount(2);

    $workflow->decide($seats[0], $approverA, 'approved');
    // 1 of 2 approved on the only existing stage — the late-added stage
    // must NOT be assigned yet, even though this decision made it look
    // like "nothing pending on this stage from this seat's perspective".
    expect(DocumentAssignment::where('document_id', $document->document_id)->where('stage_id', $lateStage->stage_id)->exists())
        ->toBeFalse();

    $workflow->decide($seats[1], $approverB, 'approved');
    // 2nd and last seat on the original stage — now the late-added stage opens.
    expect(DocumentAssignment::where('document_id', $document->document_id)->where('stage_id', $lateStage->stage_id)->exists())
        ->toBeTrue();
});

test('a lone reject on a multi-approver stage does not terminate the document — majority is required', function () {
    $originator = User::factory()->originator()->create();
    $approverA = User::factory()->approver('Job Order')->create();
    $approverB = User::factory()->approver('Job Order')->create();
    $approverC = User::factory()->approver('Job Order')->create();
    $workflow = app(WorkflowService::class);

    $document = classifiedJobOrder($originator);
    $workflow->routeToWorkflow($document);

    $stage = WorkflowStage::where('stage_name', 'Technical Review')->first();
    $seats = DocumentAssignment::where('document_id', $document->document_id)
        ->where('stage_id', $stage->stage_id)->orderBy('user_id')->get();

    // A approves, B rejects — 3 seats need 2 rejects to reach majority
    // (see DocumentAssignment::stageRejectionStatus()), so B's lone reject
    // does nothing: A's approval stands, C is still free to decide either
    // way, and the document itself is untouched.
    $workflow->decide($seats[0], $approverA, 'approved');
    $workflow->decide($seats[1], $approverB, 'rejected');

    expect($document->fresh()->global_status)->toBe('classified_validated')
        ->and($seats[0]->fresh()->individual_status)->toBe('approved')
        ->and($seats[1]->fresh()->individual_status)->toBe('rejected')
        ->and($seats[2]->fresh()->individual_status)->toBe('pending');
});

test('reaching majority reject on a multi-approver stage terminates the document', function () {
    $originator = User::factory()->originator()->create();
    $approverA = User::factory()->approver('Job Order')->create();
    $approverB = User::factory()->approver('Job Order')->create();
    $approverC = User::factory()->approver('Job Order')->create();
    $workflow = app(WorkflowService::class);

    $document = classifiedJobOrder($originator);
    $workflow->routeToWorkflow($document);

    $stage = WorkflowStage::where('stage_name', 'Technical Review')->first();
    $seats = DocumentAssignment::where('document_id', $document->document_id)
        ->where('stage_id', $stage->stage_id)->orderBy('user_id')->get();

    // 2 of 3 rejecting reaches majority — now it cascades exactly like the
    // old any-single-reject behavior did, closing every other pending seat
    // (including C, who never got to weigh in) across every stage.
    $workflow->decide($seats[0], $approverA, 'rejected');
    $workflow->decide($seats[1], $approverB, 'rejected');

    expect($document->fresh()->global_status)->toBe('rejected')
        ->and($seats[2]->fresh()->individual_status)->toBe('rejected')
        ->and($seats[2]->fresh()->cascade_closed_by)->toBe($approverB->user_id);
});

test('a stranded minority reject resets to pending once majority approval makes it impossible', function () {
    $originator = User::factory()->originator()->create();
    $approverA = User::factory()->approver('Job Order')->create();
    $approverB = User::factory()->approver('Job Order')->create();
    $approverC = User::factory()->approver('Job Order')->create();
    $workflow = app(WorkflowService::class);

    $document = classifiedJobOrder($originator);
    $workflow->routeToWorkflow($document);

    $stage = WorkflowStage::where('stage_name', 'Technical Review')->first();
    $seats = DocumentAssignment::where('document_id', $document->document_id)
        ->where('stage_id', $stage->stage_id)->orderBy('user_id')->get();

    // A rejects first (1 of 3 — not enough, stays alive). B then approves,
    // then C approves too — 2 of 3 approved means reject can never reach
    // majority (2) anymore, no matter what. A's stranded reject must reset
    // to pending rather than sit there blocking unanimous approval forever.
    $workflow->decide($seats[0], $approverA, 'rejected');
    $workflow->decide($seats[1], $approverB, 'approved');
    expect($seats[0]->fresh()->individual_status)->toBe('rejected'); // not yet stranded — only 1 of 3 approved so far

    $workflow->decide($seats[2], $approverC, 'approved');

    expect($seats[0]->fresh()->individual_status)->toBe('pending')
        ->and($seats[0]->fresh()->comments)->toBeNull()
        ->and($document->fresh()->global_status)->toBe('classified_validated');
});

test('approving every stage finalizes the document as approved', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $workflow = app(WorkflowService::class);

    $document = classifiedJobOrder($originator);
    $workflow->routeToWorkflow($document);

    $assignments = DocumentAssignment::where('document_id', $document->document_id)->get();
    foreach ($assignments as $assignment) {
        $workflow->decide($assignment, $approver, 'approved');
    }

    expect($document->fresh()->global_status)->toBe('approved');
});

test('rejecting one stage terminates the whole document and auto-closes every other pending stage', function () {
    $originator = User::factory()->originator()->create();
    // level: 'head' — this single approver needs to hold BOTH stages here
    // (Technical Review AND Final Approval) for the test to exercise cascade-
    // close across a real second pending stage; Final Approval now requires
    // it (see WorkflowService::eligibleApproversForStage()'s head-only rule)
    // or this approver would never be seated on it at all.
    $approver = User::factory()->approver('Job Order')->create(['level' => 'head']);
    $workflow = app(WorkflowService::class);

    $document = classifiedJobOrder($originator);
    $workflow->routeToWorkflow($document);

    $assignments = DocumentAssignment::where('document_id', $document->document_id)
        ->orderBy('assignment_id')
        ->get();

    // Reject only the first stage; the second was never individually decided.
    $workflow->decide($assignments->first(), $approver, 'rejected');

    $stillPendingCount = DocumentAssignment::where('document_id', $document->document_id)
        ->where('individual_status', 'pending')
        ->count();

    expect($document->fresh()->global_status)->toBe('rejected')
        ->and($stillPendingCount)->toBe(0)
        ->and($assignments->last()->fresh()->individual_status)->toBe('rejected')
        ->and($assignments->last()->fresh()->cascade_closed_by)->toBe($approver->user_id);
});
