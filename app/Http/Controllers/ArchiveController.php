<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DocumentRepository;
use App\Rules\ReliableMimeType;
use App\Services\TextExtractionService;
use App\Services\ValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * ArchiveController
 * -------------------
 * Implements the "Centralized Digital Records Repository" (Scope 1.4) and
 * DFD Process 8.0 (Search & Retrieval): a searchable list of approved
 * documents, filterable by keyword/category/date, with download.
 *
 * Access rule (per product decision — three distinct views by role):
 *   - Admin sees every category, unrestricted, and can additionally import a
 *     pre-existing, already-approved legacy document straight into the
 *     repository (bypassing classification/validation/workflow entirely).
 *   - Approver is hard-scoped to their own `assigned_category`, set once at
 *     account creation and never editable afterward. An approver with no
 *     category assigned yet cannot browse the archive at all. This makes
 *     sense because multiple approvers typically split review work by
 *     document category.
 *   - Originator is NOT scoped by category at all — an originator can
 *     upload any kind of document (the ML classifier determines its
 *     category automatically per upload), so tying their account to one
 *     category would be wrong. Instead, their Archive shows only THEIR OWN
 *     approved submissions, across every category, with an optional
 *     category filter for narrowing the search.
 */
class ArchiveController extends Controller
{
    public function __construct(private TextExtractionService $extractor)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        // Only Approver accounts can be "unassigned" in a way that blocks
        // the archive entirely — Admin is unrestricted and Originator is
        // scoped to their own submissions regardless of category.
        if ($user->isApprover() && !$user->assigned_category) {
            return view('archive.index', [
                'documents' => DocumentRepository::whereRaw('1 = 0')->paginate(12),
                'categories' => [],
                'restrictedCategory' => null,
                'noCategoryAssigned' => true,
                'isOwnSubmissionsView' => false,
            ]);
        }

        $query = DocumentRepository::query()
            ->whereIn('global_status', ['approved', 'auto_approved'])
            ->with('originator');

        $isOwnSubmissionsView = false;

        if ($user->isApprover()) {
            // Hard RBAC restriction — approvers cannot override this via input.
            $query->where('ml_category', $user->assigned_category);
        } elseif (!$user->isAdmin()) {
            // Originator: their own approved submissions, any category —
            // they may still narrow it down with the category filter below.
            $query->where('originator_id', $user->user_id);
            $isOwnSubmissionsView = true;
        }

        // Category filter is available to Admin (any category) and
        // Originator (within their own submissions); Approver's category is
        // fixed above and not user-selectable.
        if (!$user->isApprover() && $request->filled('category')) {
            $query->where('ml_category', $request->string('category'));
        }

        if ($request->filled('keyword')) {
            $keyword = $request->string('keyword');
            $query->where(function ($q) use ($keyword) {
                $q->where('title', 'like', "%{$keyword}%")
                    ->orWhere('ocr_text', 'like', "%{$keyword}%");
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('upload_date', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('upload_date', '<=', $request->date('date_to'));
        }

        $documents = $query->latest('upload_date')->paginate(12)->withQueryString();

        return view('archive.index', [
            'documents' => $documents,
            'categories' => ValidationService::knownCategories(),
            'restrictedCategory' => $user->isApprover() ? $user->assigned_category : null,
            'noCategoryAssigned' => false,
            'isOwnSubmissionsView' => $isOwnSubmissionsView,
        ]);
    }

    public function download(Request $request, DocumentRepository $document)
    {
        $user = $request->user();

        abort_unless(in_array($document->global_status, ['approved', 'auto_approved']), 404);

        if ($user->isApprover()) {
            // Re-check on the individual document, not just at list time —
            // prevents an approver from downloading via a guessed URL.
            abort_unless(
                $user->assigned_category && $document->ml_category === $user->assigned_category,
                403,
                'This document is outside your assigned category.'
            );
        } elseif (!$user->isAdmin()) {
            // Originator: can only download documents they themselves submitted.
            abort_unless(
                $document->originator_id === $user->user_id,
                403,
                'You can only download your own submissions.'
            );
        }

        abort_unless(Storage::disk('local')->exists($document->file_path), 404, 'File no longer available on disk.');

        AuditLog::record($user->user_id, $document->document_id, 'archive_download',
            "{$user->full_name} downloaded '{$document->title}' from the archive.");

        return Storage::disk('local')->download($document->file_path, $document->original_filename ?? $document->title);
    }

    /**
     * Admin-only: import a pre-existing, already-approved legacy document
     * directly into the repository. Skips the classification/validation/
     * workflow pipeline entirely since the document is already approved.
     */
    public function storeLegacy(Request $request)
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,docx,doc,txt,png,jpg,jpeg', new ReliableMimeType(), 'max:20480'],
            'category' => ['required', 'in:' . implode(',', ValidationService::knownCategories())],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $validated['file'];
        $storedPath = $file->store('documents', 'local');
        $extraction = $this->extractor->extract($file); // populates ocr_text so it stays searchable

        $document = DocumentRepository::create([
            'originator_id' => $request->user()->user_id, // attributed to the importing admin
            'title' => $validated['title'] ?: $file->getClientOriginalName(),
            'file_path' => $storedPath,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'ocr_text' => $extraction['text'],
            'used_ocr_fallback' => $extraction['used_ocr_fallback'],
            'ml_category' => $validated['category'],
            'ml_confidence' => null,
            'model_id' => null,
            'is_validated' => true,
            'validation_errors' => null,
            'global_status' => 'approved',
        ]);

        AuditLog::record($request->user()->user_id, $document->document_id, 'legacy_import',
            "Admin {$request->user()->full_name} imported pre-existing approved document '{$document->title}' (category: {$validated['category']}) directly into the archive.");

        return back()->with('status', "'{$document->title}' added to the archive.");
    }
}