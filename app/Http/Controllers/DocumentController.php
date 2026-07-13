<?php

namespace App\Http\Controllers;

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Services\WorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function __construct(private WorkflowService $workflow)
    {
    }

    /** Originator dashboard: drag-drop upload + live tracking list (DFD 3.1-3.4, 4.0). */
    public function dashboard(Request $request)
    {
        $documents = DocumentRepository::forOriginator($request->user()->user_id)
            ->with(['currentAssignment.stage', 'assignments.stage'])
            ->latest('upload_date')
            ->paginate(10);

        return view('originator.dashboard', compact('documents'));
    }

    public function store(Request $request)
    {
        // Explicit validation on every document metadata input (Section 3).
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,docx,doc,txt,png,jpg,jpeg', 'max:20480'],
            'due_date' => ['required', 'date', 'after:now'],
        ]);

        $document = $this->workflow->ingest($validated['file'], $request->user(), $validated['due_date']);

        return redirect()
            ->route('originator.dashboard')
            ->with('status', $document->is_validated
                ? "'{$document->title}' uploaded, classified as '{$document->ml_category}', and routed for approval."
                : "'{$document->title}' uploaded but failed validation — see details below.");
    }

    public function show(Request $request, DocumentRepository $document)
    {
        abort_unless($document->originator_id === $request->user()->user_id || $request->user()->isAdmin(), 403);

        $document->load(['assignments.stage', 'assignments.approver', 'auditLogs.user']);

        return view('originator.tracking', compact('document'));
    }

    /**
     * Streams the exact original uploaded file inline (not force-download)
     * for the embedded viewer on the Approver dashboard. Distinct from
     * ArchiveController::download(), which forces a "Save As" download of
     * already-approved documents; this is for reviewing a file still in
     * progress, unaltered from what the originator submitted.
     */
    public function viewFile(Request $request, DocumentRepository $document)
    {
        $user = $request->user();

        $isOwner = $document->originator_id === $user->user_id;
        $isAdmin = $user->isAdmin();
        $isAssignedApprover = $user->isApprover() && DocumentAssignment::where('document_id', $document->document_id)
            ->where('user_id', $user->user_id)
            ->exists();

        abort_unless($isOwner || $isAdmin || $isAssignedApprover, 403);

        abort_unless(Storage::disk('local')->exists($document->file_path), 404, 'File not found.');

        $path = Storage::disk('local')->path($document->file_path);
        $mime = $document->mime_type ?: 'application/octet-stream';

        return response()->file($path, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . addslashes($document->original_filename ?? $document->title) . '"',
        ]);
    }
}