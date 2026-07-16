<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\DocumentRepository;
use App\Models\SubmissionBatch;
use App\Rules\ReliableMimeType;
use App\Services\WorkflowService;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * JSON equivalent of the originator dashboard/upload flow
 * (App\Http\Controllers\DocumentController) — same validation rules and
 * the same WorkflowService::ingest() pipeline (text extraction, ML
 * classification, validation, routing), just returning JSON instead of a
 * redirect + flashed Blade view. Kept as a separate controller rather than
 * branching the web one on Accept-header, so each stays a plain, readable
 * mapping of one request shape to one response shape.
 */
class DocumentController extends Controller
{
    public function __construct(private WorkflowService $workflow)
    {
    }

    /** The authenticated originator's own submissions; Admin sees every document. */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = $user->isAdmin()
            ? DocumentRepository::query()
            : DocumentRepository::forOriginator($user->user_id);

        if ($request->filled('status')) {
            $query->where('global_status', $request->string('status'));
        }
        if ($request->filled('category')) {
            $query->where('ml_category', $request->string('category'));
        }

        $documents = $query->with('originator')->latest('upload_date')->paginate(20);

        return DocumentResource::collection($documents);
    }

    public function show(Request $request, DocumentRepository $document)
    {
        abort_unless($document->originator_id === $request->user()->user_id || $request->user()->isAdmin(), 403);

        $document->load(['assignments.stage', 'originator']);

        return new DocumentResource($document);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->isOriginator(), 403, 'Only originator accounts can submit documents.');

        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['file', 'mimes:pdf,docx,doc,txt,png,jpg,jpeg', new ReliableMimeType(), 'max:20480'],
            'due_date' => ['required', 'date', function ($attribute, $value, $fail) {
                $buffer = config('sla.min_due_date_buffer_minutes', 15);
                if (Carbon::parse($value)->lt(now()->addMinutes($buffer))) {
                    $fail("The due date must be at least {$buffer} minutes from now.");
                }
            }],
        ]);

        $effectiveDueDate = $this->workflow->resolveEffectiveDueDate($validated['due_date']);

        $batch = SubmissionBatch::create([
            'originator_id' => $request->user()->user_id,
            'due_date' => $effectiveDueDate,
        ]);

        $documents = collect($validated['files'])
            ->map(fn ($file) => $this->workflow->ingest($file, $request->user(), $effectiveDueDate->toDateTimeString(), $batch->batch_id));

        return DocumentResource::collection($documents)->response()->setStatusCode(201);
    }
}
