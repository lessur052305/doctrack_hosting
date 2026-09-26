<?php

use App\Broadcasting\FailureTolerantBroadcaster;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\SlaService;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Pusher\ApiErrorException;
use Pusher\Pusher;

/**
 * A live-update broadcast is best-effort. Its failure must never cancel the
 * action that triggered it, and one failing seat must never stop the SLA sweep
 * from processing the seats after it (this is what left approvers' overdue
 * seats pending for days when the Reverb host was misconfigured).
 */
function overdueSeat(string $title): DocumentAssignment
{
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::firstOrCreate(
        ['document_category' => 'Job Order', 'sequence_order' => 1],
        ['stage_name' => 'Technical Review']
    );

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => $title,
        'file_path' => "documents/{$title}",
        'mime_type' => 'text/plain',
        'due_date' => now()->addDays(2),
        'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
    ]);

    return DocumentAssignment::create([
        'document_id' => $document->document_id,
        'user_id' => $approver->user_id,
        'stage_id' => $stage->stage_id,
        'due_date' => now()->addDays(2),
        'priority_rank' => 2,
        'individual_status' => 'pending',
        'sla_expires_at' => now()->subMinutes(30),
    ]);
}

it('the framework broadcaster throws when the broadcast server is unreachable (control)', function () {
    $pusher = Mockery::mock(Pusher::class);
    $pusher->shouldReceive('trigger')->andThrow(new ApiErrorException('Application not found'));

    (new PusherBroadcaster($pusher))->broadcast(['admin-activity'], 'AdminActivityLogged', []);
})->throws(BroadcastException::class);

it('the failure-tolerant broadcaster reports the failure instead of throwing it', function () {
    $pusher = Mockery::mock(Pusher::class);
    $pusher->shouldReceive('trigger')->once()->andThrow(new ApiErrorException('Application not found'));

    $reported = collect();
    $this->app->make(ExceptionHandler::class)->reportable(function (BroadcastException $e) use ($reported) {
        $reported->push($e);

        return false;
    });

    (new FailureTolerantBroadcaster($pusher))->broadcast(['admin-activity'], 'AdminActivityLogged', []);

    expect($reported)->toHaveCount(1);
});

it('keeps sweeping the remaining overdue seats when one seat fails', function () {
    $first = overdueSeat('first.txt');
    $second = overdueSeat('second.txt');

    $sla = Mockery::mock(SlaService::class);
    $sla->shouldReceive('autoApproveMissedDeadline')
        ->once()
        ->with(Mockery::on(fn ($a) => $a->assignment_id === $first->assignment_id))
        ->andThrow(new RuntimeException('boom'));
    $sla->shouldReceive('autoApproveMissedDeadline')
        ->once()
        ->with(Mockery::on(fn ($a) => $a->assignment_id === $second->assignment_id));
    $this->app->instance(SlaService::class, $sla);

    $this->artisan('workflow:check-parallel-slas')
        ->expectsOutputToContain('1 overdue assignment(s) auto-approved, 1 failed.')
        ->assertExitCode(1);
});

it('reports how many overdue seats were auto-approved', function () {
    overdueSeat('one.txt');
    overdueSeat('two.txt');

    $this->artisan('workflow:check-parallel-slas')
        ->expectsOutputToContain('2 overdue assignment(s) auto-approved.')
        ->assertExitCode(0);

    expect(DocumentAssignment::where('individual_status', 'pending')->count())->toBe(0);
});

it('reports zero cleanly when nothing is overdue', function () {
    $this->artisan('workflow:check-parallel-slas')
        ->expectsOutputToContain('0 overdue assignment(s) auto-approved.')
        ->assertExitCode(0);
});
