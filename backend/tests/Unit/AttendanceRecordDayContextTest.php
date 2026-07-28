<?php

namespace Tests\Unit;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceRecordDayContextTest extends TestCase {
    use RefreshDatabase;

    public function test_day_context_fields_persist(): void {
        $employee = Employee::factory()->for(Branch::factory())->create();
        $record = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'work_date' => '2026-07-15',
            'shift_start' => '09:00:00',
            'shift_end' => '18:00:00',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'holiday_type' => 'special',
            'is_rest_day' => true,
            'absence_type' => null,
            'break_out' => '13:00:00',
            'break_in' => '14:00:00',
        ]);

        $fresh = $record->fresh();
        $this->assertSame('18:00:00', $fresh->shift_end);
        $this->assertSame('special', $fresh->holiday_type);
        $this->assertTrue($fresh->is_rest_day);
        $this->assertSame('13:00:00', $fresh->break_out);
    }
}
