<?php

use App\Models\User;
use App\Services\PasswordBreachCheck;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The live "not a known leaked password" hint and the submit-time gate.
 * The hint reports three honest states (clean / leaked / unavailable); the
 * gate — Laravel's uncompromised() — stays fail-open, so an outage of the
 * breach service never stops an Admin from creating an account.
 */
function breachRangeFor(string $password, int $count): string
{
    $hash = strtoupper(sha1($password));

    return substr($hash, 5).":{$count}\r\n0000000000000000000000000000000000A:0\r\n";
}

function fakeBreachApi(string $password, int $count): void
{
    Http::fake(['api.pwnedpasswords.com/*' => Http::response(breachRangeFor($password, $count))]);
}

function validNewAccount(string $password): array
{
    return [
        'username' => 'new_staff',
        'full_name' => 'New Staff',
        'email' => 'new.staff@example.test',
        'role' => 'originator',
        'password' => $password,
    ];
}

it('reports a password found in a breach as leaked', function () {
    fakeBreachApi('Password123', 9000);

    expect(app(PasswordBreachCheck::class)->check('Password123'))->toBe('leaked');
});

it('reports a password absent from the breach list as clean', function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response("0000000000000000000000000000000000B:4\r\n")]);

    expect(app(PasswordBreachCheck::class)->check('Qz8kVn4RTwmp'))->toBe('clean');
});

it('does not treat a zero-count padding entry as a match', function () {
    fakeBreachApi('Qz8kVn4RTwmp', 0);

    expect(app(PasswordBreachCheck::class)->check('Qz8kVn4RTwmp'))->toBe('clean');
});

it('sends only the five-character hash prefix, never the password', function () {
    fakeBreachApi('Password123', 1);

    app(PasswordBreachCheck::class)->check('Password123');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.pwnedpasswords.com/range/'.substr(strtoupper(sha1('Password123')), 0, 5)
            && ! str_contains($request->url(), 'Password123');
    });
});

it('reports unavailable when the breach service errors or cannot be reached', function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 503)]);
    expect(app(PasswordBreachCheck::class)->check('Password123'))->toBe('unavailable');

    Http::fake(['api.pwnedpasswords.com/*' => fn () => throw new ConnectionException('timeout')]);
    expect(app(PasswordBreachCheck::class)->check('Password123'))->toBe('unavailable');
});

it('serves the breach hint to a guest on the reset-password page, uncached', function () {
    fakeBreachApi('Password123', 500);

    $this->postJson(route('password.breach-check'), ['password' => 'Password123'])
        ->assertOk()
        ->assertJson(['status' => 'leaked'])
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('serves the breach hint to a logged-in Admin', function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);

    $this->actingAs(User::factory()->admin()->create())
        ->postJson(route('password.breach-check'), ['password' => 'Qz8kVn4RTwmp'])
        ->assertOk()
        ->assertJson(['status' => 'clean']);
});

it('reports unavailable through the endpoint when the breach service is down', function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 500)]);

    $this->postJson(route('password.breach-check'), ['password' => 'Password123'])
        ->assertOk()
        ->assertJson(['status' => 'unavailable']);
});

it('requires a password for the breach hint', function () {
    $this->postJson(route('password.breach-check'), [])->assertJsonValidationErrors('password');
});

it('rejects a known-leaked password when creating an account', function () {
    fakeBreachApi('Password123', 9000);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.users.store'), validNewAccount('Password123'))
        ->assertSessionHasErrors('password');

    expect(User::where('username', 'new_staff')->exists())->toBeFalse();
});

it('accepts a clean password when creating an account', function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.users.store'), validNewAccount('Qz8kVn4RTwmp'))
        ->assertSessionHasNoErrors();

    expect(User::where('username', 'new_staff')->exists())->toBeTrue();
});

it('still accepts a strong password when the breach service is down (fail-open)', function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 503)]);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.users.store'), validNewAccount('Qz8kVn4RTwmp'))
        ->assertSessionHasNoErrors();

    expect(User::where('username', 'new_staff')->exists())->toBeTrue();
});

it('embeds the breach-check endpoint and CSRF token in the password checklist', function () {
    Http::fake();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.users'))
        ->assertOk()
        ->assertSee('data-breach-check-url="'.route('password.breach-check').'"', false)
        ->assertSee('data-csrf="', false);
});
