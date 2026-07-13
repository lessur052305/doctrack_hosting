<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\ArchiveController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
| Every protected route below is wrapped in auth + role:<roles> middleware.
| No route relies on implicit access — each declares exactly which of the
| three roles (admin, originator, approver) may reach it, per Section 3's
| "strict RBAC middleware" requirement.
*/

Route::get('/', fn () => redirect()->route('login'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // --- Originator ---
    Route::middleware('role:originator')->prefix('originator')->name('originator.')->group(function () {
        Route::get('/dashboard', [DocumentController::class, 'dashboard'])->name('dashboard');
        Route::post('/documents', [DocumentController::class, 'store'])->name('documents.store');
        Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
        Route::get('/archive', [ArchiveController::class, 'index'])->name('archive');
    });

    // --- Approver ---
    Route::middleware('role:approver')->prefix('approver')->name('approver.')->group(function () {
        Route::get('/dashboard', [ApprovalController::class, 'dashboard'])->name('dashboard');
        Route::post('/assignments/{assignment}/decide', [ApprovalController::class, 'decide'])->name('assignments.decide');
        Route::post('/availability/toggle', [ApprovalController::class, 'toggleAvailability'])->name('availability.toggle');
        Route::get('/archive', [ArchiveController::class, 'index'])->name('archive');
    });

    // --- Admin ---
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');

        Route::get('/users', [AdminController::class, 'users'])->name('users');
        Route::post('/users', [AdminController::class, 'storeUser'])->name('users.store');
        Route::post('/users/{user}/toggle', [AdminController::class, 'toggleUser'])->name('users.toggle');
        Route::get('/users/{user}/stages', [AdminController::class, 'editApproverStages'])->name('users.stages.edit');
        Route::post('/users/{user}/stages', [AdminController::class, 'updateApproverStages'])->name('users.stages.update');

        Route::get('/ml-training', [AdminController::class, 'mlTraining'])->name('ml.training');
        Route::post('/ml-training', [AdminController::class, 'trainModel'])->name('ml.train');

        Route::get('/sla-queue', [AdminController::class, 'slaQueue'])->name('sla.queue');
        Route::post('/sla-queue/{assignment}/override', [AdminController::class, 'override'])->name('sla.override');

        Route::get('/workflow-config', [AdminController::class, 'workflowConfig'])->name('workflow.config');
        Route::post('/workflow-config', [AdminController::class, 'storeStage'])->name('workflow.store');

        Route::get('/audit-logs', [AdminController::class, 'auditLogs'])->name('audit.logs');

        Route::get('/archive', [ArchiveController::class, 'index'])->name('archive');
        Route::post('/archive/legacy', [ArchiveController::class, 'storeLegacy'])->name('archive.legacy');
    });

    // Archive download is shared across all three roles; ArchiveController
    // itself re-checks category ownership per-document for staff (Section 3
    // RBAC — list-level filtering alone is not sufficient).
    Route::middleware('role:admin,originator,approver')
        ->get('/archive/{document}/download', [ArchiveController::class, 'download'])
        ->name('archive.download');

    // Admin may also inspect any document's tracking page for support purposes.
    Route::middleware('role:admin,originator')->get('/documents/{document}/track', [DocumentController::class, 'show'])
        ->name('documents.track');

    // Inline (not force-download) original-file viewer, embedded in the
    // Approver dashboard and reachable by the originator/admin too.
    // Permission is re-checked per-document inside the controller: owner,
    // admin, or an approver who actually has an assignment for it.
    Route::middleware('role:admin,originator,approver')
        ->get('/documents/{document}/file', [DocumentController::class, 'viewFile'])
        ->name('documents.file');
});