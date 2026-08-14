<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatutoryRefreshOnAttendanceEditTest extends TestCase {
    use RefreshDatabase;

    private function employeeWithDays(int $days): Employee {
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => 600]);
        foreach (range(1, $days) as $day) {
            AttendanceRecord::factory()->for($employee)->create([
                'work_date' => sprintf('2026-07-%02d', $day),
                'shift_start' => '08:00:00', 'shift_end' => '17:00:00',
                'clock_in' => '08:00:00', 'clock_out' => '17:00:00', 'status' => 'approved',
            ]);
        }

        return $employee;
    }

    public function test_editing_attendance_refreshes_the_periods_sss(): void {
        $admin = User::factory()->create();
        $employee = $this->employeeWithDays(13);

        $this->actingAs($admin)->postJson('/api/admin/payroll/period/statutory?month=2026-07&period=first')->assertOk();
        // 13 days x 600 = 7,800 -> the 7,750-8,249.99 bracket -> 400.
        $sss = PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'sss')->firstOrFail();
        $this->assertSame('-400.00', $sss->amount);

        // Correcting a day down to a half shift drops the period's net earnings
        // into a lower bracket; the stored contribution should follow.
        AttendanceRecord::where('employee_id', $employee->id)->whereDate('work_date', '2026-07-13')->firstOrFail()
            ->update(['clock_out' => '12:00:00']);

        $this->assertNotSame('-400.00', $sss->fresh()->amount);
    }

    public function test_deleting_a_day_refreshes_the_periods_sss(): void {
        $admin = User::factory()->create();
        $employee = $this->employeeWithDays(13);
        $this->actingAs($admin)->postJson('/api/admin/payroll/period/statutory?month=2026-07&period=first')->assertOk();
        $sss = PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'sss')->firstOrFail();

        AttendanceRecord::where('employee_id', $employee->id)->whereDate('work_date', '2026-07-13')->firstOrFail()->delete();

        // 12 days x 600 = 7,200 -> the 6,750-7,249.99 bracket -> 350.
        $this->assertSame('-350.00', $sss->fresh()->amount);
    }

    public function test_a_hand_entered_row_is_not_refreshed(): void {
        $employee = $this->employeeWithDays(13);
        $manual = PayrollAdjustment::create([
            'employee_id' => $employee->id, 'date' => '2026-07-15', 'label' => 'SSS',
            'category' => 'sss', 'amount' => -180.00, 'paid' => false,
            'reason' => 'Agreed with the employee', 'created_by' => User::factory()->create()->id,
        ]);

        AttendanceRecord::where('employee_id', $employee->id)->whereDate('work_date', '2026-07-13')->firstOrFail()
            ->update(['clock_out' => '12:00:00']);

        $this->assertSame('-180.00', $manual->fresh()->amount);
    }

    public function test_no_row_is_created_for_a_period_never_generated(): void {
        $employee = $this->employeeWithDays(13);

        AttendanceRecord::where('employee_id', $employee->id)->whereDate('work_date', '2026-07-13')->firstOrFail()
            ->update(['clock_out' => '12:00:00']);

        $this->assertSame(0, PayrollAdjustment::where('employee_id', $employee->id)->count());
    }

    public function test_the_other_cutoff_is_left_alone(): void {
        $admin = User::factory()->create();
        $employee = $this->employeeWithDays(13);
        foreach (range(16, 28) as $day) {
            AttendanceRecord::factory()->for($employee)->create([
                'work_date' => sprintf('2026-07-%02d', $day),
                'shift_start' => '08:00:00', 'shift_end' => '17:00:00',
                'clock_in' => '08:00:00', 'clock_out' => '17:00:00', 'status' => 'approved',
            ]);
        }
        $this->actingAs($admin)->postJson('/api/admin/payroll/period/statutory?month=2026-07&period=first')->assertOk();
        $this->actingAs($admin)->postJson('/api/admin/payroll/period/statutory?month=2026-07&period=second')->assertOk();
        $secondSss = PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'sss')
            ->whereDate('date', '2026-07-31')->firstOrFail();

        AttendanceRecord::where('employee_id', $employee->id)->whereDate('work_date', '2026-07-13')->firstOrFail()
            ->update(['clock_out' => '12:00:00']);

        $this->assertSame('-400.00', $secondSss->fresh()->amount);
    }
}
