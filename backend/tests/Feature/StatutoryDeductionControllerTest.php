<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatutoryDeductionControllerTest extends TestCase {
    use RefreshDatabase;

    private function workedDay(Employee $employee, string $date): void {
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => $date, 'shift_start' => '08:00:00', 'shift_end' => '17:00:00',
            'clock_in' => '08:00:00', 'clock_out' => '17:00:00', 'status' => 'approved',
        ]);
    }

    public function test_generate_creates_pagibig_philhealth_and_sss_deductions_for_a_cutoff(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => null]);
        $this->workedDay($employee, '2026-07-20');

        $response = $this->actingAs($admin)->postJson('/api/admin/payroll/period/statutory?month=2026-07&period=second');

        $response->assertOk();
        $response->assertJson(['generated' => ['pagibig' => 1, 'philhealth' => 1, 'sss' => 1]]);

        $pagibig = PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'pagibig')->firstOrFail();
        $this->assertSame('-100.00', $pagibig->amount);

        // 8h ordinary day at 505/day => hourly 63.125, base_wage 505.00, PhilHealth 2.5% = 12.63.
        $philhealth = PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'philhealth')->firstOrFail();
        $this->assertSame('-12.63', $philhealth->amount);

        // Cutoff net = 505 (only day worked) -> "Below 5,250" bracket, employee
        // share 250, charged in full — the share is never halved.
        $sss = PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'sss')->firstOrFail();
        $this->assertSame('-250.00', $sss->amount);
    }

    public function test_sss_bracket_uses_the_cutoff_gross_less_tardiness(): void {
        // Reproduces the client's worksheet: 13 days at ₱600/day = ₱7,800 gross,
        // less 20 min tardiness (₱25.00) = ₱7,775.00 net, which sits in the
        // ₱7,750–8,249.99 bracket -> ₱400 employee share for the cutoff.
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => 600]);
        foreach (range(1, 13) as $day) {
            AttendanceRecord::factory()->for($employee)->create([
                'work_date' => sprintf('2026-07-%02d', $day),
                'shift_start' => '08:00:00', 'shift_end' => '17:00:00',
                'clock_in' => $day === 1 ? '08:20:00' : '08:00:00', 'clock_out' => '17:00:00',
            ]);
        }

        $this->actingAs($admin)->postJson('/api/admin/payroll/period/statutory?month=2026-07&period=first')->assertOk();

        $sss = PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'sss')->firstOrFail();
        $this->assertSame('-400.00', $sss->amount);
    }

    public function test_generate_uses_200_pagibig_and_full_sss_share_for_a_whole_month_period(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => null]);
        $this->workedDay($employee, '2026-07-10');

        $this->actingAs($admin)->postJson('/api/admin/payroll/period/statutory?month=2026-07&period=whole')->assertOk();

        $pagibig = PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'pagibig')->firstOrFail();
        $this->assertSame('-200.00', $pagibig->amount);

        // Whole-month net = 505 -> "Below 5,250" bracket, 250 share, not split.
        $sss = PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'sss')->firstOrFail();
        $this->assertSame('-250.00', $sss->amount);
    }

    public function test_generate_is_idempotent_and_skips_already_generated_entries(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => null]);
        $this->workedDay($employee, '2026-07-20');

        $this->actingAs($admin)->postJson('/api/admin/payroll/period/statutory?month=2026-07&period=second')->assertOk();
        $second = $this->actingAs($admin)->postJson('/api/admin/payroll/period/statutory?month=2026-07&period=second')
            ->assertOk();

        $second->assertJson([
            'generated' => ['pagibig' => 0, 'philhealth' => 0, 'sss' => 0],
            'skipped' => ['pagibig' => 1, 'philhealth' => 1, 'sss' => 1],
        ]);
        $this->assertSame(1, PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'pagibig')->count());
        $this->assertSame(1, PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'philhealth')->count());
        $this->assertSame(1, PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'sss')->count());
    }

    public function test_generate_skips_philhealth_and_sss_for_an_employee_with_no_pay_activity(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => null]);
        // No attendance in the window at all.

        $this->actingAs($admin)->postJson('/api/admin/payroll/period/statutory?month=2026-07&period=second')->assertOk();

        // Pag-IBIG is flat and still applies; PhilHealth and SSS need actual pay activity.
        $this->assertSame(1, PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'pagibig')->count());
        $this->assertSame(0, PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'philhealth')->count());
        $this->assertSame(0, PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'sss')->count());
    }

    public function test_branch_login_only_generates_for_its_own_branch(): void {
        $ownBranch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $manager = User::factory()->create(['role' => 'branch', 'branch_id' => $ownBranch->id]);
        $ownEmployee = Employee::factory()->for($ownBranch)->create(['daily_basic_rate' => null]);
        $otherEmployee = Employee::factory()->for($otherBranch)->create(['daily_basic_rate' => null]);
        $this->workedDay($ownEmployee, '2026-07-20');
        $this->workedDay($otherEmployee, '2026-07-20');

        $this->actingAs($manager)->postJson('/api/admin/payroll/period/statutory?month=2026-07&period=second')->assertOk();

        $this->assertSame(3, PayrollAdjustment::where('employee_id', $ownEmployee->id)->count());
        $this->assertSame(0, PayrollAdjustment::where('employee_id', $otherEmployee->id)->count());
    }

    public function test_generate_requires_authentication(): void {
        $this->postJson('/api/admin/payroll/period/statutory?month=2026-07&period=second')->assertStatus(401);
    }
}
