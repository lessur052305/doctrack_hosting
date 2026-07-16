<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class MlStagingSampleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category' => 'Job Order',
            'original_filename' => fake()->word() . '.txt',
            'extracted_text' => fake()->paragraphs(3, true),
            'staged_by' => User::factory()->admin(),
            'created_at' => now(),
        ];
    }
}
