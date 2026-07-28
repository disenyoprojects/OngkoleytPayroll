<?php

namespace Tests\Unit;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComputeForRecordTest extends TestCase {
    use RefreshDatabase;

    public function test_uses_per_day_shift_and_special_holiday_premium(): void {
        $employee = Employee::factory()->for(Branch::factory())->create([
            'shift_start' => '08:00:00', 'shift_end' => '17:00:00', 'daily_basic_rate' => null,
        ]);
        $record = AttendanceRecord::create([
            'employee_id' => $employee->id, 'work_date' => '2026-07-15',
            'shift_start' => '09:00:00', 'shift_end' => '18:00:00',
            'clock_in' => '09:00:00', 'clock_out' => '18:00:00',
            'holiday_type' => 'special',
        ]);
        $record->setRelation('employee', $employee);

        $settings = PayrollSetting::current();
        $pay = (new AttendancePayCalculator())->computeForRecord($record, $settings);

        // 8h at 63.125 * 1.30 = 656.50 (uses the record's 09:00-18:00 shift, not the employee default)
        $this->assertSame(656.50, $pay['basic']);
    }

    public function test_falls_back_to_employee_shift_when_record_shift_missing(): void {
        $employee = Employee::factory()->for(Branch::factory())->create([
            'shift_start' => '08:00:00', 'shift_end' => '17:00:00', 'daily_basic_rate' => null,
        ]);
        $record = AttendanceRecord::create([
            'employee_id' => $employee->id, 'work_date' => '2026-07-10',
            'clock_in' => '08:00:00', 'clock_out' => '17:00:00',
        ]);
        $record->setRelation('employee', $employee);

        $pay = (new AttendancePayCalculator())->computeForRecord($record, PayrollSetting::current());
        // 08:00-17:00 (9h) - 1h break = 8h regular, ordinary rate => 505.00
        $this->assertSame(505.00, $pay['basic']);
    }
}
