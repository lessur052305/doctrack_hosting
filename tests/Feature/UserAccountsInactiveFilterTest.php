<?php

use App\Models\User;

test('inactive accounts are hidden from the list by default', function () {
    $admin = User::factory()->admin()->create();
    $inactiveUser = User::factory()->originator()->create(['is_active' => false, 'full_name' => 'Inactive Person']);

    $response = $this->actingAs($admin)->get(route('admin.users'));

    $response->assertOk();
    $response->assertDontSee('Inactive Person');
    $response->assertSee('Show inactive accounts');
});

test('show_inactive=1 reveals inactive accounts and the toggle switches to hide', function () {
    $admin = User::factory()->admin()->create();
    $inactiveUser = User::factory()->originator()->create(['is_active' => false, 'full_name' => 'Inactive Person']);

    $response = $this->actingAs($admin)->get(route('admin.users', ['show_inactive' => 1]));

    $response->assertOk();
    $response->assertSee('Inactive Person');
    $response->assertSee('Hide inactive accounts');
});

test('active accounts are always visible regardless of the toggle', function () {
    $admin = User::factory()->admin()->create();
    $activeUser = User::factory()->originator()->create(['is_active' => true, 'full_name' => 'Active Person']);

    $this->actingAs($admin)->get(route('admin.users'))->assertSee('Active Person');
    $this->actingAs($admin)->get(route('admin.users', ['show_inactive' => 1]))->assertSee('Active Person');
});

test('the toggle link does not appear when there are no inactive accounts to show', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('admin.users'));

    $response->assertOk();
    $response->assertDontSee('Show inactive accounts');
});
