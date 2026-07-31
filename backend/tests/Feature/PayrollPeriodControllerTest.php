<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollPeriodControllerTest extends TestCase {
    use RefreshDatabase;

    private function workedDay(Employee $employee, string $date): void {
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => $date, 'shift_start' => '08:00:00', 'shift_end' => '17:00:00',
            'clock_in' => '08:00:00', 'clock_out' => '17:00:00', 'status' => 'approved',
        ]);
    }

    public function test_period_register_lists_one_row_per_active_employee_with_totals(): void {
        $admin = User::factory()->create();
        $branch = Branch::factory()->create();
        $a = Employee::factory()->for($branch)->create(['daily_basic_rate' => null, 'short_name' => 'Aaa']);
        $b = Employee::factory()->for($branch)->create(['daily_basic_rate' => null, 'short_name' => 'Bbb']);
        $this->workedDay($a, '2026-07-20');
        $this->workedDay($b, '2026-07-21');

        $res = $this->actingAs($admin)->getJson('/api/admin/payroll/period?month=2026-07&period=second')
            ->assertOk();

        $res->assertJsonCount(2, 'rows');
        $this->assertEqualsWithDelta(
            $res->json('rows.0.gross') + $res->json('rows.1.gross'),
            $res->json('totals.gross'),
            0.01
        );
    }

    public function test_paid_allowance_adds_to_total_salary_but_not_net_to_release(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => null]);
        $this->workedDay($employee, '2026-07-20');
        PayrollAdjustment::create([
            'employee_id' => $employee->id, 'date' => '2026-07-31', 'label' => 'Allowance (paid in cash)',
            'category' => 'allowance', 'amount' => 300, 'paid' => true,
        ]);

        $row = $this->actingAs($admin)->getJson('/api/admin/payroll/period?month=2026-07&period=second')
            ->assertOk()->json('rows.0');

        // Allowance is in Total Salary...
        $this->assertEqualsWithDelta($row['gross'] + 300, $row['total_salary'], 0.01);
        // ...but excluded from Net to Release (already handed out in cash).
        $this->assertEqualsWithDelta($row['gross'], $row['net_to_release'], 0.01);
    }

    public function test_cash_advance_deduction_reduces_net_to_release(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => null]);
        $this->workedDay($employee, '2026-07-20');
        PayrollAdjustment::create([
            'employee_id' => $employee->id, 'date' => '2026-07-31', 'label' => 'Cash advance',
            'category' => 'deduction', 'amount' => -2000, 'paid' => false,
        ]);

        $row = $this->actingAs($admin)->getJson('/api/admin/payroll/period?month=2026-07&period=second')
            ->assertOk()->json('rows.0');

        $this->assertEqualsWithDelta($row['gross'] - 2000, $row['net_to_release'], 0.01);
    }

    public function test_last_day_of_window_is_included_regardless_of_datetime_storage(): void {
        // Regression: whereDate boundary must include Jul 31 records/adjustments.
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => null]);
        $this->workedDay($employee, '2026-07-31');

        $this->actingAs($admin)->getJson("/api/admin/employees/{$employee->id}/payslip?month=2026-07&period=second")
            ->assertOk()
            ->assertJsonCount(1, 'lines');
    }

    public function test_period_requires_valid_month_and_period(): void {
        $admin = User::factory()->create();
        $this->actingAs($admin)->getJson('/api/admin/payroll/period?month=2026-07&period=bogus')
            ->assertStatus(422);
    }
}
