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
        ];
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
