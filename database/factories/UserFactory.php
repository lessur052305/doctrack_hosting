<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $passwordHash;

    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'password_hash' => static::$passwordHash ??= Hash::make('password'),
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'role' => 'originator',
            'is_active' => true,
            // Verified by default — most tests exercise everything AFTER
            // login (actingAs() bypasses AuthController::login() entirely
            // anyway), not the verification gate itself. Tests that
            // specifically need an unverified account use ->unverified().
            'email_verified_at' => now(),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => 'admin']);
    }

    public function originator(): static
    {
        return $this->state(fn () => ['role' => 'originator']);
    }

    public function approver(string $category = 'Job Order'): static
    {
        return $this->state(fn () => ['role' => 'approver', 'assigned_category' => $category]);
    }
}
