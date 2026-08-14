<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\StatutoryDeductionController;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecomputeSssCommandTest extends TestCase {
    use RefreshDatabase;

    private function employeeWithWrongSss(string $code, float $wrongAmount): Employee {
        $employee = Employee::factory()->for(Branch::factory())->create([
            'employee_code' => $code, 'daily_basic_rate' => null,
        ]);
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-07-20', 'shift_start' => '08:00:00', 'shift_end' => '17:00:00',
            'clock_in' => '08:00:00', 'clock_out' => '17:00:00', 'status' => 'approved',
        ]);
        PayrollAdjustment::create([
            'employee_id' => $employee->id, 'date' => '2026-07-31', 'label' => 'SSS',
            'category' => 'sss', 'amount' => $wrongAmount, 'paid' => false,
            'reason' => StatutoryDeductionController::AUTO_REASON,
            'created_by' => User::factory()->create()->id,
        ]);

        return $employee;
    }

    /** One 8h day at ₱505 -> "Below 5,250" bracket -> the correct share is 250. */
    public function test_preview_reports_the_correction_without_writing_it(): void {
        $employee = $this->employeeWithWrongSss('ONG-1', -125.00);

        $this->artisan('payroll:recompute-sss')
            ->expectsOutputToContain('1 row(s) would change')
            ->assertSuccessful();

        $this->assertSame('-125.00', PayrollAdjustment::where('employee_id', $employee->id)->firstOrFail()->amount);
    }

    public function test_apply_writes_the_correction(): void {
        $employee = $this->employeeWithWrongSss('ONG-1', -125.00);

        $this->artisan('payroll:recompute-sss --apply')->assertSuccessful();

        $this->assertSame('-250.00', PayrollAdjustment::where('employee_id', $employee->id)->firstOrFail()->amount);
    }

    public function test_excluded_employee_is_left_untouched(): void {
        $kept = $this->employeeWithWrongSss('ONG-KEEP', -125.00);
        $fixed = $this->employeeWithWrongSss('ONG-FIX', -125.00);

        $this->artisan('payroll:recompute-sss --exclude=ONG-KEEP --apply')->assertSuccessful();

        $this->assertSame('-125.00', PayrollAdjustment::where('employee_id', $kept->id)->firstOrFail()->amount);
        $this->assertSame('-250.00', PayrollAdjustment::where('employee_id', $fixed->id)->firstOrFail()->amount);
    }

    public function test_a_hand_entered_row_is_never_touched(): void {
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => null]);
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-07-20', 'shift_start' => '08:00:00', 'shift_end' => '17:00:00',
            'clock_in' => '08:00:00', 'clock_out' => '17:00:00', 'status' => 'approved',
        ]);
        PayrollAdjustment::create([
            'employee_id' => $employee->id, 'date' => '2026-07-31', 'label' => 'SSS',
            'category' => 'sss', 'amount' => -125.00, 'paid' => false,
            'reason' => 'Agreed with the employee', 'created_by' => User::factory()->create()->id,
        ]);

        $this->artisan('payroll:recompute-sss --apply')->assertSuccessful();

        $this->assertSame('-125.00', PayrollAdjustment::where('employee_id', $employee->id)->firstOrFail()->amount);
    }

    public function test_month_filter_limits_which_periods_are_touched(): void {
        $employee = $this->employeeWithWrongSss('ONG-1', -125.00);

        $this->artisan('payroll:recompute-sss --month=2026-06 --apply')->assertSuccessful();

        $this->assertSame('-125.00', PayrollAdjustment::where('employee_id', $employee->id)->firstOrFail()->amount);
    }
}
