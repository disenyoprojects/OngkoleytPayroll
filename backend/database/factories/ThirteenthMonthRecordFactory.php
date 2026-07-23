<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\ThirteenthMonthRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

class ThirteenthMonthRecordFactory extends Factory {
    protected $model = ThirteenthMonthRecord::class;

    public function definition(): array {
        return [
            'employee_id' => Employee::factory(),
            'payroll_year' => now()->year,
            'computed_amount' => 0,
            'manual_adjustment' => 0,
            'status' => 'pending',
        ];
    }
}
