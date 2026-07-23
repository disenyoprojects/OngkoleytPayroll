<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_code' => 'ONG-' . fake()->unique()->numberBetween(2000, 9999),
            'full_name' => fake()->name(),
            'short_name' => fake()->firstName(),
            'role' => fake()->jobTitle(),
            'branch_id' => Branch::factory(),
            'employment_type' => fake()->randomElement(['regular', 'probationary', 'fixed_term', 'seasonal']),
            'hire_date' => now()->startOfYear(),
            'resignation_date' => null,
            'pin_hash' => bcrypt('1234'),
        ];
    }
}
