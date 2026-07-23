<?php

namespace Database\Factories;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttendanceRecordFactory extends Factory {
    protected $model = AttendanceRecord::class;

    public function definition(): array {
        return [
            'employee_id' => Employee::factory(),
            'work_date' => now()->toDateString(),
            'shift_start' => '08:00:00',
            'clock_in' => '08:00:00',
            'clock_out' => null,
            'status' => 'pending',
        ];
    }
}
