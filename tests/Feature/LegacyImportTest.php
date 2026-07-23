<?php

use App\Models\AuditLog;
use App\Models\DocumentRepository;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * Regression coverage for #7 (legacy import bypasses everything): the
 * import itself is intentional (digitizing pre-existing approved paperwork),
 * but it must be visibly flagged as admin-injected rather than peer-reviewed,
 * and must record why.
 */
it('rejects a legacy import with no justification', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->post(route('admin.archive.legacy'), [
        'file' => UploadedFile::fake()->createWithContent('old.txt', 'legacy content'),
        'category' => 'Job Order',
        // import_reason omitted
    ]);

    $response->assertSessionHasErrors('import_reason');
    expect(DocumentRepository::count())->toBe(0);
});

it('rejects a justification under 10 characters as not meaningful', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->post(route('admin.archive.legacy'), [
        'file' => UploadedFile::fake()->createWithContent('old.txt', 'legacy content'),
        'category' => 'Job Order',
        'import_reason' => 'why not',
    ]);

    $response->assertSessionHasErrors('import_reason');
});

it('flags an imported document and records the reason in the audit trail', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('admin.archive.legacy'), [
        'file' => UploadedFile::fake()->createWithContent('old-po.txt', 'legacy purchase order content'),
        'category' => 'Purchase Requisition',
        'import_reason' => 'Digitizing 2019 paper records approved before this system existed.',
    ])->assertRedirect();

    $document = DocumentRepository::firstOrFail();

    expect($document->is_legacy_import)->toBeTrue();
    expect($document->global_status)->toBe('approved');

    $log = AuditLog::where('document_id', $document->document_id)->where('action_type', 'legacy_import')->firstOrFail();
    expect($log->description)->toContain('Digitizing 2019 paper records');
});

it('shows the imported badge on the archive listing', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('admin.archive.legacy'), [
        'file' => UploadedFile::fake()->createWithContent('old-po.txt', 'legacy purchase order content'),
        'category' => 'Purchase Requisition',
        'import_reason' => 'Digitizing 2019 paper records approved before this system existed.',
    ]);

    // The bare archive URL now shows the folder-grid landing screen (see
    // ArchiveController::index()'s $showFolders) — a category filter is
    // what transitions into the flat results view the badge renders in.
    $response = $this->actingAs($admin)->get(route('admin.archive', ['category' => 'Purchase Requisition']));

    $response->assertOk();
    $response->assertSee('Imported');
});
