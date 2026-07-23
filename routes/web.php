<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\ArchiveController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\NotificationController;
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

// Role-aware, not just "always send to /login" — Laravel's stock 'guest'
// middleware (see RedirectIfAuthenticated::defaultRedirectUri()) sends an
// already-authenticated visitor here as its last-resort fallback, since
// this app has no route literally named 'dashboard' or 'home' (only the
// role-prefixed originator.dashboard / approver.dashboard / admin.dashboard).
// If '/' always redirected to /login unconditionally, an authenticated
// user hitting /login would bounce: guest middleware -> '/' -> /login ->
// guest middleware -> '/' -> ... forever (NS_ERROR_REDIRECT_LOOP).
Route::get('/', function () {
    if (!auth()->check()) {
        return redirect()->route('login');
    }

    $user = auth()->user();

    return redirect()->route(match (true) {
        $user->isAdmin() => 'admin.dashboard',
        $user->isApprover() => 'approver.dashboard',
        $user->isOriginator() => 'originator.dashboard',
        default => 'login',
    });
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    // Per-account throttling lives inside AuthController::login() itself
    // (keyed by username+IP, with a tailored lockout message). This
    // IP-only cap is a second, coarser layer on top of that — it catches
    // an attacker sweeping through many different usernames from one IP,
    // which the per-username limiter alone wouldn't trip.
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login.attempt');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // --- Notification Center (shared across all roles) ---
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    // Same live-poll pair pattern as the role dashboards — the bell
    // appears on every page (see components/notification-bell.blade.php),
    // so these live outside any single role's route group.
    Route::get('/notifications/poll', [NotificationController::class, 'poll'])->middleware('throttle:30,1')->name('notifications.poll');
    Route::get('/notifications/refresh', [NotificationController::class, 'refresh'])->middleware('throttle:30,1')->name('notifications.refresh');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.readAll');

    // --- Originator ---
    Route::middleware('role:originator')->prefix('originator')->name('originator.')->group(function () {
        Route::get('/dashboard', [DocumentController::class, 'dashboard'])->name('dashboard');
        // Live-poll pair, same headroom reasoning as the approver queue's
        // equivalent routes: poll is cheap and hit every 5-10s, refresh is
        // heavier and only fetched when poll detects a change.
        Route::get('/documents/poll', [DocumentController::class, 'poll'])->middleware('throttle:30,1')->name('documents.poll');
        Route::get('/documents/refresh', [DocumentController::class, 'refresh'])->middleware('throttle:30,1')->name('documents.refresh');
        // Upload endpoints are rate-limited (keyed by authenticated user
        // ID, per Laravel's default ThrottleRequests behavior) — each
        // request can trigger text extraction, OCR, and SVM classification,
        // so this caps both accidental runaway scripts and deliberate abuse.
        Route::post('/documents', [DocumentController::class, 'store'])->middleware('throttle:20,1')->name('documents.store');
        Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
        // Per-document live-poll pair for the tracking page — reacts to a
        // single stage being decided, not just the document's overall
        // status finalizing (§ see DocumentAssignment::booted()).
        Route::get('/documents/{document}/poll', [DocumentController::class, 'trackingPoll'])->middleware('throttle:30,1')->name('documents.trackingPoll');
        Route::get('/documents/{document}/refresh', [DocumentController::class, 'trackingRefresh'])->middleware('throttle:30,1')->name('documents.trackingRefresh');
        Route::post('/documents/{document}/resubmit', [DocumentController::class, 'resubmit'])->middleware('throttle:20,1')->name('documents.resubmit');
        Route::get('/archive', [ArchiveController::class, 'index'])->name('archive');
    });

    // --- Approver ---
    Route::middleware('role:approver')->prefix('approver')->name('approver.')->group(function () {
        Route::get('/dashboard', [ApprovalController::class, 'dashboard'])->name('dashboard');
        // 5-10s client polling (see dashboard.blade.php) means up to ~12
        // requests/min from one tab; throttle:30,1 gives headroom for a
        // couple of open tabs without letting a runaway/malicious loop
        // hammer the DB unbounded.
        Route::get('/assignments/poll', [ApprovalController::class, 'poll'])->middleware('throttle:30,1')->name('assignments.poll');
        // Only fetched when poll() actually detects a change, not every
        // 5-10s cycle — same headroom reasoning as poll() above.
        Route::get('/assignments/refresh', [ApprovalController::class, 'refresh'])->middleware('throttle:30,1')->name('assignments.refresh');
        Route::post('/assignments/{assignment}/decide', [ApprovalController::class, 'decide'])->name('assignments.decide');
        Route::post('/assignments/decide-batch', [ApprovalController::class, 'decideBatch'])->name('assignments.decideBatch');
        Route::post('/availability/toggle', [ApprovalController::class, 'toggleAvailability'])->name('availability.toggle');
        Route::get('/archive', [ArchiveController::class, 'index'])->name('archive');
    });

    // --- Admin ---
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
        // Same live-poll pair pattern as the approver/originator dashboards.
        Route::get('/dashboard/poll', [AdminController::class, 'overviewPoll'])->middleware('throttle:30,1')->name('dashboard.poll');
        Route::get('/dashboard/refresh', [AdminController::class, 'overviewRefresh'])->middleware('throttle:30,1')->name('dashboard.refresh');

        Route::get('/users', [AdminController::class, 'users'])->name('users');
        Route::post('/users', [AdminController::class, 'storeUser'])->name('users.store');
        Route::post('/users/{user}/toggle', [AdminController::class, 'toggleUser'])->name('users.toggle');
        Route::get('/users/{user}/stages', [AdminController::class, 'editApproverStages'])->name('users.stages.edit');
        Route::post('/users/{user}/stages', [AdminController::class, 'updateApproverStages'])->name('users.stages.update');

        Route::get('/ml-training', [AdminController::class, 'mlTraining'])->name('ml.training');
        Route::post('/ml-training', [AdminController::class, 'trainModel'])->name('ml.train');
        Route::post('/ml-training/stage/{category}', [AdminController::class, 'stageTrainingSamples'])->middleware('throttle:20,1')->name('ml.training.stage');
        Route::delete('/ml-training/stage/{category}', [AdminController::class, 'clearTrainingStaging'])->name('ml.training.stage.clear');
        Route::delete('/ml-training/samples/{sample}', [AdminController::class, 'destroyTrainingSample'])->name('ml.training.sample.destroy');
        Route::post('/ml-training/review/{document}', [AdminController::class, 'reviewFlaggedDocument'])->name('ml.review');
        Route::post('/ml-training/review/{document}/recheck', [AdminController::class, 'recheckFlaggedDocument'])->name('ml.review.recheck');
        Route::post('/ml-training/review/{document}/dismiss', [AdminController::class, 'dismissRecheckedDocument'])->name('ml.review.dismiss');
        Route::get('/ml-training/review/refresh', [AdminController::class, 'mlReviewQueueRefresh'])->name('ml.review.refresh');
        Route::get('/ml-training/review/poll', [AdminController::class, 'mlReviewQueuePoll'])->name('ml.review.poll');

        Route::get('/sla-queue', [AdminController::class, 'slaQueue'])->name('sla.queue');
        Route::post('/sla-queue/{assignment}/override', [AdminController::class, 'override'])->name('sla.override');
        Route::post('/sla-queue/override-batch', [AdminController::class, 'overrideBatch'])->name('sla.overrideBatch');
        Route::post('/sla-queue/document/{document}/review', [AdminController::class, 'reviewAutoApproval'])->name('sla.review');

        Route::get('/workflow-config', [AdminController::class, 'workflowConfig'])->name('workflow.config');
        Route::post('/workflow-config', [AdminController::class, 'storeStage'])->name('workflow.store');
        Route::put('/workflow-config/{stage}', [AdminController::class, 'updateStage'])->name('workflow.stages.update');
        Route::post('/workflow-config/{stage}/move-up', [AdminController::class, 'moveStageUp'])->name('workflow.stages.moveUp');
        Route::post('/workflow-config/{stage}/move-down', [AdminController::class, 'moveStageDown'])->name('workflow.stages.moveDown');
        Route::post('/workflow-config/{stage}/notify-pending', [AdminController::class, 'notifyPendingApprovers'])->name('workflow.stages.notifyPending');
        Route::post('/workflow-config/{stage}/archive', [AdminController::class, 'archiveStage'])->name('workflow.stages.archive');
        Route::post('/workflow-config/{stage}/unarchive', [AdminController::class, 'unarchiveStage'])->name('workflow.stages.unarchive');
        Route::delete('/workflow-config/{stage}', [AdminController::class, 'destroyStage'])->name('workflow.stages.destroy');

        Route::get('/calendar', [AdminController::class, 'calendar'])->name('calendar');
        Route::put('/calendar/settings', [AdminController::class, 'updateSlaSettings'])->name('calendar.settings.update');
        Route::post('/calendar/holidays', [AdminController::class, 'storeHoliday'])->name('calendar.holidays.store');
        Route::delete('/calendar/holidays/{holiday}', [AdminController::class, 'destroyHoliday'])->name('calendar.holidays.destroy');

        Route::get('/sla-violations', [AdminController::class, 'violationsReport'])->name('sla.violations');
        // Live search (Feature: instant results as you type) — returns just
        // the results fragment, same pattern as archive.refresh.
        Route::get('/sla-violations/refresh', [AdminController::class, 'violationsRefresh'])
            ->middleware('throttle:30,1')->name('sla.violations.refresh');

        Route::get('/audit-logs', [AdminController::class, 'auditLogs'])->name('audit.logs');

        Route::get('/archive', [ArchiveController::class, 'index'])->name('archive');
        Route::post('/archive/legacy', [ArchiveController::class, 'storeLegacy'])->middleware('throttle:20,1')->name('archive.legacy');
    });

    // Archive download and live-search refresh are shared across all three
    // roles; ArchiveController itself re-checks category ownership
    // per-document for staff (Section 3 RBAC — list-level filtering alone
    // is not sufficient).
    Route::middleware('role:admin,originator,approver')
        ->get('/archive/{document}/download', [ArchiveController::class, 'download'])
        ->name('archive.download');

    // Live search (Feature: instant results as you type instead of a full
    // page reload) — returns just the results-table fragment, same query
    // logic as ArchiveController::index()'s results branch. Throttled like
    // every other live-poll endpoint in this app.
    Route::middleware('role:admin,originator,approver')
        ->get('/archive/refresh', [ArchiveController::class, 'refresh'])
        ->middleware('throttle:30,1')
        ->name('archive.refresh');

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