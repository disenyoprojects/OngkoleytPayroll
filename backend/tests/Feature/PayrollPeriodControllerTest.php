<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
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

    public function test_period_pdf_streams_a_pdf(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => null]);
        $this->workedDay($employee, '2026-07-20');

        $res = $this->actingAs($admin)->get('/api/admin/payroll/period/pdf?month=2026-07&period=second');

        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
        $this->assertNotEmpty($res->getContent());
    }

    public function test_period_pdf_requires_authentication(): void {
        $this->getJson('/api/admin/payroll/period/pdf?month=2026-07&period=second')->assertStatus(401);
    }

    public function test_owner_pdf_filename_and_manager_pdf_filename_differ(): void {
        $admin = User::factory()->create(['role' => 'admin']);
        $branch = Branch::factory()->create(['name' => 'Mabini']);
        $manager = User::factory()->create(['role' => 'branch', 'branch_id' => $branch->id]);
        $employee = Employee::factory()->for($branch)->create(['daily_basic_rate' => null]);
        $this->workedDay($employee, '2026-07-20');

        $ownerRes = $this->actingAs($admin)->get('/api/admin/payroll/period/pdf?month=2026-07&period=second')->assertOk();
        $this->assertStringContainsString('payroll-owner-', $ownerRes->headers->get('content-disposition'));

        $managerRes = $this->actingAs($manager)->get('/api/admin/payroll/period/pdf?month=2026-07&period=second')->assertOk();
        $this->assertStringContainsString('payroll-mabini-', $managerRes->headers->get('content-disposition'));
    }

    public function test_owner_document_is_grouped_by_branch_with_a_company_wide_total(): void {
        $admin = User::factory()->create(['role' => 'admin']);
        $mabini = Branch::factory()->create(['name' => 'Mabini']);
        $diego = Branch::factory()->create(['name' => 'Diego']);
        $a = Employee::factory()->for($mabini)->create(['daily_basic_rate' => null]);
        $b = Employee::factory()->for($diego)->create(['daily_basic_rate' => null]);
        $this->workedDay($a, '2026-07-20');
        $this->workedDay($b, '2026-07-21');

        $register = $this->actingAs($admin)
            ->getJson('/api/admin/payroll/period?month=2026-07&period=second')
            ->assertOk()->json();
        $register['rows'] = Collection::make($register['rows']);

        $html = view('pdf.payroll-period', ['register' => $register, 'isAdmin' => true, 'branchName' => null])->render();

        $this->assertStringContainsString('Company-Wide Payroll Register', $html);
        $this->assertStringContainsString('Mabini', $html);
        $this->assertStringContainsString('Diego', $html);
        $this->assertStringContainsString('subtotal', $html);
        $this->assertStringContainsString('COMPANY-WIDE TOTAL', $html);
    }

    public function test_manager_document_is_titled_to_their_branch_with_no_company_total(): void {
        $admin = User::factory()->create(['role' => 'admin']);
        $branch = Branch::factory()->create(['name' => 'Mabini']);
        $employee = Employee::factory()->for($branch)->create(['daily_basic_rate' => null]);
        $this->workedDay($employee, '2026-07-20');

        $register = $this->actingAs($admin)
            ->getJson('/api/admin/payroll/period?month=2026-07&period=second')
            ->assertOk()->json();
        $register['rows'] = Collection::make($register['rows']);

        $html = view('pdf.payroll-period', ['register' => $register, 'isAdmin' => false, 'branchName' => 'Mabini'])->render();

        $this->assertStringContainsString('Branch Payroll Register — Mabini', $html);
        $this->assertStringNotContainsString('Company-Wide Payroll Register', $html);
        $this->assertStringNotContainsString('COMPANY-WIDE TOTAL', $html);
        $this->assertStringContainsString('owner only', $html);
    }
}
