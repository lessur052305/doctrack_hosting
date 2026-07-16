<?php

use App\Models\DocumentRepository;
use App\Models\User;

it('reports current stats via poll', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'doc.txt', 'file_path' => 'documents/doc.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addHours(2), 'global_status' => 'approved',
    ]);

    $response = $this->actingAs($admin)->getJson(route('admin.dashboard.poll'));

    $response->assertOk()->assertJsonStructure(['stats' => ['total_documents', 'pending', 'approved', 'rejected', 'active_users'], 'sla_alert_count']);
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
    expect($response->getContent())->toContain('SLA Override Alerts');
    expect($response->getContent())->toContain('Active ML Model');
});

it('rejects poll/refresh requests from a non-admin', function () {
    $originator = User::factory()->originator()->create();

    $this->actingAs($originator)->getJson(route('admin.dashboard.poll'))->assertForbidden();
    $this->actingAs($originator)->get(route('admin.dashboard.refresh'))->assertForbidden();
});
