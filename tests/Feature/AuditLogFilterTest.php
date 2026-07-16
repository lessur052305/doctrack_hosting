<?php

use App\Models\AuditLog;
use App\Models\User;

/**
 * Regression coverage for the "Show login/logout events" checkbox on the
 * admin audit log page — it must hide both the web (login/logout) and API
 * (api_login/api_logout) session-noise action types consistently, not just
 * the web ones. Caught via a screenshot showing api_login entries visible
 * while web login entries were correctly hidden by the same checkbox.
 */
it('hides both web and API session events by default', function () {
    $admin = User::factory()->admin()->create();
    AuditLog::record($admin->user_id, null, 'login', 'User admin logged in.');
    AuditLog::record($admin->user_id, null, 'logout', 'User admin logged out.');
    AuditLog::record($admin->user_id, null, 'api_login', 'User admin authenticated via API.');
    AuditLog::record($admin->user_id, null, 'api_logout', 'User admin revoked their API token.');
    AuditLog::record($admin->user_id, null, 'ml_train', 'Trained a model.');

    $response = $this->actingAs($admin)->get(route('admin.audit.logs'));

    $response->assertOk();
    $response->assertDontSee('User admin logged in.');
    $response->assertDontSee('User admin logged out.');
    $response->assertDontSee('User admin authenticated via API.');
    $response->assertDontSee('User admin revoked their API token.');
    $response->assertSee('Trained a model.');
});

it('shows all session events (web and API) when the checkbox is checked', function () {
    $admin = User::factory()->admin()->create();
    AuditLog::record($admin->user_id, null, 'login', 'User admin logged in.');
    AuditLog::record($admin->user_id, null, 'api_login', 'User admin authenticated via API.');

    $response = $this->actingAs($admin)->get(route('admin.audit.logs', ['show_session' => 1]));

    $response->assertOk();
    $response->assertSee('User admin logged in.');
    $response->assertSee('User admin authenticated via API.');
});
