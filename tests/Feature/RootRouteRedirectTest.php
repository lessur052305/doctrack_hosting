<?php

use App\Models\User;

it('sends a guest visiting / to the login page', function () {
    $this->get('/')->assertRedirect(route('login'));
});

it('sends an authenticated admin visiting / to the admin dashboard', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/')->assertRedirect(route('admin.dashboard'));
});

it('sends an authenticated approver visiting / to the approver dashboard', function () {
    $approver = User::factory()->approver('Job Order')->create();

    $this->actingAs($approver)->get('/')->assertRedirect(route('approver.dashboard'));
});

it('sends an authenticated originator visiting / to the originator dashboard', function () {
    $originator = User::factory()->originator()->create();

    $this->actingAs($originator)->get('/')->assertRedirect(route('originator.dashboard'));
});

it('does not loop when an already-authenticated user revisits /login', function () {
    // Regression test: the 'guest' middleware's default fallback (no route
    // named 'dashboard' or 'home' exists in this app) sends an
    // already-authenticated visitor back to '/'. Before this fix, '/'
    // unconditionally redirected to /login regardless of auth state,
    // producing an infinite redirect loop the moment someone with a live
    // session revisited /login directly. It's now a terminating two-hop
    // chain (/login -> / -> the real dashboard) instead of a loop — assert
    // the first hop explicitly, then follow the whole chain to prove it
    // actually lands somewhere real rather than bouncing forever.
    $originator = User::factory()->originator()->create();

    $this->actingAs($originator)->get('/login')->assertRedirect('/');

    $this->actingAs($originator)->followingRedirects()->get('/login')
        ->assertOk()
        ->assertSee('New Submission');
});
