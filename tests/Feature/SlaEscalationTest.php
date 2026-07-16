<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\NotificationRecord;
use App\Models\SlaViolation;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\SlaService;
use Carbon\Carbon;

function pendingAssignment(array $overrides = []): DocumentAssignment
{
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'test.txt',
        'file_path' => 'documents/test.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
    ]);

    return DocumentAssignment::create(array_merge([
        'document_id' => $document->document_id,
        'user_id' => $approver->user_id,
        'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date,
        'priority_rank' => 2,
        'individual_status' => 'pending',
        'sla_expires_at' => now()->subMinutes(5),
    ], $overrides));
}

test('escalating a breached assignment flags it, logs a violation, and notifies admins', function () {
    $admin = User::factory()->admin()->create();
    $assignment = pendingAssignment();

    app(SlaService::class)->escalate($assignment);

    expect($assignment->fresh()->escalated_to_admin)->toBeTrue()
        ->and(SlaViolation::where('assignment_id', $assignment->assignment_id)->exists())->toBeTrue()
        ->and(NotificationRecord::where('recipient_id', $admin->user_id)->where('priority', 'high')->exists())->toBeTrue();
});

test('an admin override resolves the assignment directly, bypassing the original approver', function () {
    $admin = User::factory()->admin()->create();
    $assignment = pendingAssignment();
    app(SlaService::class)->escalate($assignment);

    app(SlaService::class)->adminOverride($assignment, $admin, 'approved', 'Resolved on the approver\'s behalf.');

    $fresh = $assignment->fresh();
    expect($fresh->individual_status)->toBe('approved')
        ->and($fresh->admin_override_by)->toBe($admin->user_id)
        ->and($fresh->admin_override_at)->not->toBeNull();
});

test('an assignment still unresolved past the admin grace window is auto-approved', function () {
    $assignment = pendingAssignment([
        'sla_expires_at' => now()->subHours(13), // past the 12-hour grace window
        'escalated_to_admin' => true,
    ]);

    $count = app(SlaService::class)->sweep()['auto_approved'];

    expect($count)->toBe(1)
        ->and($assignment->fresh()->individual_status)->toBe('approved')
        ->and($assignment->fresh()->auto_approved)->toBeTrue();
});

test('an escalated assignment still within the grace window is left alone', function () {
    $assignment = pendingAssignment([
        'sla_expires_at' => now()->subHours(2), // well within the 12-hour grace window
        'escalated_to_admin' => true,
    ]);

    $count = app(SlaService::class)->sweep()['auto_approved'];

    expect($count)->toBe(0)
        ->and($assignment->fresh()->individual_status)->toBe('pending');
});
