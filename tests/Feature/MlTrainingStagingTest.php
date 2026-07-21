<?php

use App\Models\MlModelRepository;
use App\Models\MlStagingSample;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * Regression coverage for the max_file_uploads bug: a single request
 * carrying samples for all 3 categories at once (up to 30 files) can
 * silently exceed PHP's max_file_uploads ini limit, so the training form
 * stages samples per category across separate requests instead — stored in
 * a shared ml_staging_samples table (not the session), so progress survives
 * logout/session expiry and is visible to every admin, not just whoever
 * uploaded it.
 */
function admin(): User
{
    return User::factory()->admin()->create();
}

it('accumulates staged samples for a category across multiple requests', function () {
    $this->actingAs(admin())
        ->post(route('admin.ml.training.stage', 'Job Order'), [
            'files' => [UploadedFile::fake()->createWithContent('a.txt', 'job order sample one'), UploadedFile::fake()->createWithContent('b.txt', 'job order sample two')],
        ])->assertSessionHasNoErrors();

    $this->actingAs(admin())
        ->post(route('admin.ml.training.stage', 'Job Order'), [
            'files' => [UploadedFile::fake()->createWithContent('c.txt', 'job order sample three')],
        ])->assertSessionHasNoErrors();

    expect(MlStagingSample::where('category', 'Job Order')->count())->toBe(3);
});

it('shares staged samples across different admin accounts', function () {
    $adminOne = admin();
    $adminTwo = admin();

    $this->actingAs($adminOne)->post(route('admin.ml.training.stage', 'Job Order'), [
        'files' => [UploadedFile::fake()->createWithContent('a.txt', 'sample one')],
    ]);

    // A second, different admin logs in later and sees + adds to the same staging.
    $this->actingAs($adminTwo)->post(route('admin.ml.training.stage', 'Job Order'), [
        'files' => [UploadedFile::fake()->createWithContent('b.txt', 'sample two')],
    ]);

    $samples = MlStagingSample::where('category', 'Job Order')->get();
    expect($samples)->toHaveCount(2);
    expect($samples->pluck('staged_by')->all())->toEqual([$adminOne->user_id, $adminTwo->user_id]);
});

it('survives logout — staging is not tied to the browser session', function () {
    $admin = admin();

    $this->actingAs($admin)->post(route('admin.ml.training.stage', 'Job Order'), [
        'files' => [UploadedFile::fake()->createWithContent('a.txt', 'sample one')],
    ]);

    $this->post(route('logout'));
    $this->app['session']->flush(); // simulate a brand-new session (different browser/device)

    expect(MlStagingSample::where('category', 'Job Order')->count())->toBe(1);
});

it('rejects staging more than 20 samples for a single category', function () {
    MlStagingSample::factory()->count(19)->create(['category' => 'Job Order']);

    $this->actingAs(admin())
        ->post(route('admin.ml.training.stage', 'Job Order'), [
            'files' => [UploadedFile::fake()->createWithContent('a.txt', 'one'), UploadedFile::fake()->createWithContent('b.txt', 'two')],
        ])->assertSessionHasErrors('files');
});

it('removes a single staged sample without clearing the rest of its category', function () {
    $admin = admin();
    $this->actingAs($admin)->post(route('admin.ml.training.stage', 'Job Order'), [
        'files' => [UploadedFile::fake()->createWithContent('a.txt', 'one'), UploadedFile::fake()->createWithContent('b.txt', 'two')],
    ]);
    $sample = MlStagingSample::where('original_filename', 'a.txt')->firstOrFail();

    $this->actingAs($admin)->delete(route('admin.ml.training.sample.destroy', $sample));

    expect(MlStagingSample::where('category', 'Job Order')->count())->toBe(1);
    expect(MlStagingSample::find($sample->id))->toBeNull();
});

it('trains the model once all three categories have enough staged samples, keeping the staged samples afterward', function () {
    $categories = ['Job Order', 'Purchase Requisition', 'Service Report'];
    $user = admin();

    foreach ($categories as $category) {
        $files = collect(range(1, 5))->map(fn ($i) => UploadedFile::fake()->createWithContent("{$i}.txt", "{$category} sample {$i} " . str_repeat('lorem ipsum dolor sit amet ', 5)))->all();
        $this->actingAs($user)
            ->post(route('admin.ml.training.stage', $category), ['files' => $files])
            ->assertSessionHasNoErrors();
    }

    $response = $this->actingAs($user)->post(route('admin.ml.train'));

    $response->assertRedirect();
    expect(MlModelRepository::where('is_active', true)->exists())->toBeTrue();
    // Staged samples are no longer wiped after training — they persist so
    // a future upload session can add more and combine into a bigger corpus.
    expect(MlStagingSample::count())->toBe(15);
});

it('combines samples from two separate staging sessions into one training run', function () {
    $categories = ['Job Order', 'Purchase Requisition', 'Service Report'];
    $user = admin();

    $stage = function (int $start, int $end) use ($categories, $user) {
        foreach ($categories as $category) {
            $files = collect(range($start, $end))->map(
                fn ($i) => UploadedFile::fake()->createWithContent("{$i}.txt", "{$category} sample {$i} " . str_repeat('lorem ipsum dolor sit amet ', 5))
            )->all();
            $this->actingAs($user)
                ->post(route('admin.ml.training.stage', $category), ['files' => $files])
                ->assertSessionHasNoErrors();
        }
    };

    // First session: 5 per category, enough to train on its own.
    $stage(1, 5);
    $this->actingAs($user)->post(route('admin.ml.train'))->assertRedirect();
    $firstModel = MlModelRepository::where('is_active', true)->sole();
    expect($firstModel->training_sample_count)->toBe(15);

    // A later session adds 5 more per category — combined with what's
    // still staged from the first session, not starting over from zero.
    $stage(6, 10);
    expect(MlStagingSample::count())->toBe(30);

    $this->actingAs($user)->post(route('admin.ml.train'))->assertRedirect();
    $secondModel = MlModelRepository::where('is_active', true)->sole();

    expect($secondModel->model_id)->not->toBe($firstModel->model_id)
        ->and($secondModel->training_sample_count)->toBe(30);
});

it('blocks training when a category has fewer than 5 staged samples', function () {
    $user = admin();

    $this->actingAs($user)->post(route('admin.ml.training.stage', 'Job Order'), [
        'files' => [UploadedFile::fake()->createWithContent('a.txt', 'only one sample')],
    ]);

    $this->actingAs($user)->post(route('admin.ml.train'))->assertStatus(422);
});

it('warns when a newly staged sample is a near-duplicate of one already staged in the same category', function () {
    $user = admin();
    $paragraph = 'repair the leaking split-type air conditioning unit in the third floor conference room, '
        . 'which has been producing a rattling noise during operation and needs to be addressed before '
        . 'the next scheduled meeting in that room';

    $this->actingAs($user)->post(route('admin.ml.training.stage', 'Job Order'), [
        'files' => [UploadedFile::fake()->createWithContent('a.txt', "Job Order No JO-1. Requested by Juan Santos. Description of Work: {$paragraph}")],
    ])->assertSessionHasNoErrors();

    $response = $this->actingAs($user)->post(route('admin.ml.training.stage', 'Job Order'), [
        'files' => [UploadedFile::fake()->createWithContent('b.txt', "Job Order No JO-2. Requested by Maria Reyes. Description of Work: {$paragraph}")],
    ]);

    $response->assertSessionHas('warning');
    expect(session('warning'))->toHaveCount(1)
        ->and(session('warning')[0])->toContain('b.txt')
        ->and(session('warning')[0])->toContain('a.txt');
});

it('does not warn when newly staged samples are genuinely different from what is already staged', function () {
    $user = admin();

    $this->actingAs($user)->post(route('admin.ml.training.stage', 'Job Order'), [
        'files' => [UploadedFile::fake()->createWithContent('a.txt', 'Job Order No JO-1. Requested by Juan Santos, IT Department. Description of Work: replacement of a cracked window pane in the reception area facing the parking lot.')],
    ]);

    $response = $this->actingAs($user)->post(route('admin.ml.training.stage', 'Job Order'), [
        'files' => [UploadedFile::fake()->createWithContent('b.txt', 'Company Job Order Form. This Job Order No. JO-2 is submitted for approval. Requested by Maria Reyes, on behalf of Finance Department. Description of work: preventive maintenance of the backup generator ahead of the scheduled monthly test.')],
    ]);

    $response->assertSessionMissing('warning');
});

it('catches near-duplicate files uploaded together in the same request', function () {
    $user = admin();
    $paragraph = 'repair the leaking split-type air conditioning unit in the third floor conference room, '
        . 'which has been producing a rattling noise during operation and needs to be addressed before '
        . 'the next scheduled meeting in that room';

    $response = $this->actingAs($user)->post(route('admin.ml.training.stage', 'Job Order'), [
        'files' => [
            UploadedFile::fake()->createWithContent('a.txt', "Job Order No JO-1. Requested by Juan Santos. Description of Work: {$paragraph}"),
            UploadedFile::fake()->createWithContent('b.txt', "Job Order No JO-2. Requested by Maria Reyes. Description of Work: {$paragraph}"),
        ],
    ]);

    $response->assertSessionHas('warning');
});
