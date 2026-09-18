<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;

it('reports current stats via poll', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'doc.txt', 'file_path' => 'documents/doc.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addHours(2), 'global_status' => 'approved',
    ]);

    $response = $this->actingAs($admin)->getJson(route('admin.dashboard.poll'));

    $response->assertOk()->assertJsonStructure(['stats' => ['total_documents', 'pending', 'approved', 'rejected', 'active_users'], 'review_count']);
    expect($response->json('stats.total_documents'))->toBe(1);
    expect($response->json('stats.approved'))->toBe(1);
});

it('changes the poll signal when a document status changes', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $doc = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'doc.txt', 'file_path' => 'documents/doc.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addHours(2), 'global_status' => 'processing',
    ]);

    $before = $this->actingAs($admin)->getJson(route('admin.dashboard.poll'))->json();

    $doc->update(['global_status' => 'approved']);

    $after = $this->actingAs($admin)->getJson(route('admin.dashboard.poll'))->json();

    expect($after)->not->toEqual($before);
});

it('renders the overview fragment reflecting current document stats', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'rejected_doc.txt', 'file_path' => 'documents/rejected.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addHours(2), 'global_status' => 'rejected',
    ]);

    $response = $this->actingAs($admin)->get(route('admin.dashboard.refresh'));

    $response->assertOk();
    expect($response->getContent())->toContain('Auto-Approval Alerts');
});

it('shows real document names in the Auto-Approval Alerts preview, not just counts', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Only Stage', 'sequence_order' => 1]);

    $autoApprovedDoc = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'auto-approved-visible.txt', 'file_path' => 'documents/a.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addDay(), 'global_status' => 'auto_approved',
    ]);
    DocumentAssignment::create([
        'document_id' => $autoApprovedDoc->document_id, 'user_id' => null, 'stage_id' => $stage->stage_id,
        'due_date' => $autoApprovedDoc->due_date, 'priority_rank' => 2, 'individual_status' => 'auto_approved',
        'sla_expires_at' => now()->subHour(), 'acted_at' => now(), 'auto_approved' => true,
    ]);

    $response = $this->actingAs($admin)->get(route('admin.dashboard'));

    $response->assertOk()->assertSee('auto-approved-visible.txt');
});

it('rejects poll/refresh requests from a non-admin', function () {
    $originator = User::factory()->originator()->create();

    $this->actingAs($originator)->getJson(route('admin.dashboard.poll'))->assertForbidden();
    $this->actingAs($originator)->get(route('admin.dashboard.refresh'))->assertForbidden();
});
