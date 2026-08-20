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

    public function test_sss_bracket_includes_allowances_and_bonuses(): void {
        // Reproduces Ruby Rose Anudon's Aug 1–15 cutoff: ten 11:00–20:00 days
        // at ₱505 = ₱5,050, plus a ₱3,000 rice allowance. Wages alone would sit
        // in the ₱5,749.99 bracket (₱275); with the allowance the compensation
        // is ₱8,050, which is the ₱8,249.99 bracket -> ₱400.
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => 505]);
        foreach (range(1, 10) as $day) {
            AttendanceRecord::factory()->for($employee)->create([
                'work_date' => sprintf('2026-08-%02d', $day),
                'shift_start' => '11:00:00', 'shift_end' => '20:00:00',
                'clock_in' => '11:00:00', 'clock_out' => '20:00:00', 'status' => 'approved',
            ]);
        }
        PayrollAdjustment::create([
            'employee_id' => $employee->id, 'date' => '2026-08-10', 'label' => 'Rice Allowance',
            'category' => 'rice_allowance', 'amount' => 3000.00, 'paid' => false,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->postJson('/api/admin/payroll/period/statutory?month=2026-08&period=first')->assertOk();

        $sss = PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'sss')->firstOrFail();
        $this->assertSame('-400.00', $sss->amount);

        // PhilHealth stays on the basic wage only — ten flat days, no break credit.
        $philhealth = PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'philhealth')->firstOrFail();
        $this->assertSame('-126.25', $philhealth->amount);
    }

    public function test_a_deduction_does_not_lower_the_sss_bracket(): void {
        // Deductions come out of the pay, not out of the compensation the
        // bracket is read from — and the generator's own rows must not feed back.
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => 505]);
        foreach (range(1, 11) as $day) {
            $this->workedDay($employee, sprintf('2026-08-%02d', $day));
        }
        PayrollAdjustment::create([
            'employee_id' => $employee->id, 'date' => '2026-08-10', 'label' => 'Cash Advance',
            'category' => 'cash_advance', 'amount' => -2000.00, 'paid' => false,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->postJson('/api/admin/payroll/period/statutory?month=2026-08&period=first')->assertOk();

        // 11 days x 505 = 5,555 -> the 5,749.99 bracket, untouched by the -2,000.
        $sss = PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'sss')->firstOrFail();
        $this->assertSame('-275.00', $sss->amount);
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
            'updated' => ['pagibig' => 0, 'philhealth' => 0, 'sss' => 0],
            'skipped' => ['pagibig' => 1, 'philhealth' => 1, 'sss' => 1],
        ]);
        $this->assertSame(1, PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'pagibig')->count());
        $this->assertSame(1, PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'philhealth')->count());
        $this->assertSame(1, PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'sss')->count());
    }

    public function test_rerunning_corrects_an_amount_this_generator_wrote_earlier(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => null]);
        $this->workedDay($employee, '2026-07-20');

        $this->actingAs($admin)->postJson('/api/admin/payroll/period/statutory?month=2026-07&period=second')->assertOk();
        $sss = PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'sss')->firstOrFail();
        // Stand in for a row written by an older, wrong rule (the halved share).
        $sss->update(['amount' => -125.00]);

        $this->actingAs($admin)->postJson('/api/admin/payroll/period/statutory?month=2026-07&period=second')
            ->assertOk()
            ->assertJson(['updated' => ['sss' => 1]]);

        $this->assertSame('-250.00', $sss->fresh()->amount);
        // Still one row — corrected in place, not duplicated.
        $this->assertSame(1, PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'sss')->count());
    }

    public function test_rerunning_leaves_a_hand_entered_row_alone(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => null]);
        $this->workedDay($employee, '2026-07-20');
        $manual = PayrollAdjustment::create([
            'employee_id' => $employee->id, 'date' => '2026-07-20', 'label' => 'SSS',
            'category' => 'sss', 'amount' => -180.00, 'paid' => false,
            'reason' => 'Agreed with the employee', 'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->postJson('/api/admin/payroll/period/statutory?month=2026-07&period=second')
            ->assertOk()
            ->assertJson(['skipped' => ['sss' => 1]]);

        $this->assertSame('-180.00', $manual->fresh()->amount);
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
