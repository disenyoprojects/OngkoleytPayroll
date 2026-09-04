<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A cutoff nobody generated shows no SSS, Pag-IBIG or PhilHealth at all, and
 * looks like an ordinary payslip while doing it. These pin the flag that tells
 * "owes nothing" apart from "somebody forgot to press Generate".
 */
class StatutoryCoverageTest extends TestCase {
    use RefreshDatabase;

    private function employeeWithADay(): Employee {
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => 505]);
        AttendanceRecord::create([
            'employee_id' => $employee->id, 'work_date' => '2026-08-20',
            'shift_start' => '08:00:00', 'shift_end' => '17:00:00',
            'clock_in' => '08:00:00', 'clock_out' => '17:00:00', 'status' => 'approved',
        ]);

        return $employee;
    }

    private function payslip(User $admin, Employee $employee): array {
        return $this->actingAs($admin)
            ->getJson("/api/admin/employees/{$employee->id}/payslip?month=2026-08&period=second")
            ->assertOk()->json();
    }

    public function test_an_ungenerated_period_reports_all_three_missing(): void {
        $admin = User::factory()->create();
        $employee = $this->employeeWithADay();

        $this->assertSame(
            ['sss', 'philhealth', 'pagibig'],
            $this->payslip($admin, $employee)['statutory_missing'],
        );
    }

    public function test_generating_clears_the_flag(): void {
        $admin = User::factory()->create();
        $employee = $this->employeeWithADay();

        $this->actingAs($admin)
            ->postJson('/api/admin/payroll/period/statutory?month=2026-08&period=second')->assertOk();

        $this->assertSame([], $this->payslip($admin, $employee)['statutory_missing']);
    }

    /** A contribution somebody typed in is a deliberate answer, not an omission. */
    public function test_a_hand_entered_row_counts_as_covered(): void {
        $admin = User::factory()->create();
        $employee = $this->employeeWithADay();

        PayrollAdjustment::create([
            'employee_id' => $employee->id, 'date' => '2026-08-31', 'label' => 'SSS',
            'category' => 'sss', 'amount' => -180.00, 'paid' => false,
            'reason' => 'Agreed with the employee', 'created_by' => $admin->id,
        ]);

        $this->assertSame(
            ['philhealth', 'pagibig'],
            $this->payslip($admin, $employee)['statutory_missing'],
        );
    }

    /** Nothing earned, nothing owed — warning on it would train people to ignore it. */
    public function test_a_period_with_no_earnings_is_not_flagged(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => 505]);

        $this->assertSame([], $this->payslip($admin, $employee)['statutory_missing']);
    }

    public function test_the_register_counts_how_many_are_short(): void {
        $admin = User::factory()->create();
        $this->employeeWithADay();
        $this->employeeWithADay();

        $before = $this->actingAs($admin)
            ->getJson('/api/admin/payroll/period?month=2026-08&period=second')->assertOk()->json();
        $this->assertSame(2, $before['statutory_ungenerated']);

        $this->actingAs($admin)
            ->postJson('/api/admin/payroll/period/statutory?month=2026-08&period=second')->assertOk();

        $after = $this->actingAs($admin)
            ->getJson('/api/admin/payroll/period?month=2026-08&period=second')->assertOk()->json();
        $this->assertSame(0, $after['statutory_ungenerated']);
    }
}
