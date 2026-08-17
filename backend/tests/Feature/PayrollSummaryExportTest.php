<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The payroll summary spreadsheet, in the client's column order. */
class PayrollSummaryExportTest extends TestCase {
    use RefreshDatabase;

    private function csvFor(User $admin): array {
        $content = $this->actingAs($admin)
            ->get('/api/admin/payroll/period/export?month=2026-08&period=first')
            ->assertOk()->streamedContent();

        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($content))));

        return array_map(fn ($row) => array_map(fn ($cell) => trim($cell, "\xEF\xBB\xBF"), $row), $rows);
    }

    public function test_the_header_matches_the_clients_columns(): void {
        $admin = User::factory()->create();
        $rows = $this->csvFor($admin);

        // Row 0 is the period label; row 1 is the header.
        $this->assertSame([
            'Employee', 'Branch', 'Basic', 'OT', 'NSD', 'Late', 'SH', 'RH', 'UT',
            'Total Auth. Ded.', 'Penalty Lates', 'CA etc', 'SSS', 'PhilHealth', 'Pag-IBIG',
            'Days Worked', 'Rice Allowance', 'Daily Rate', 'Gross', 'Net Pay',
        ], $rows[1]);
    }

    public function test_each_deduction_type_lands_in_its_own_column(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create([
            'daily_basic_rate' => 505, 'full_name' => 'Ruby Rose Anudon',
        ]);
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-08-03', 'shift_start' => '08:00:00', 'shift_end' => '17:00:00',
            'clock_in' => '08:00:00', 'clock_out' => '17:00:00', 'status' => 'approved',
        ]);

        foreach ([
            ['penalty_late', 'Penalty Late', -100.00],
            ['cash_advance', 'Cash Advance', -250.00],
            ['rice_allowance', 'Rice Allowance', 300.00],
            ['sss', 'SSS', -250.00],
        ] as [$category, $label, $amount]) {
            PayrollAdjustment::create([
                'employee_id' => $employee->id, 'date' => '2026-08-10', 'label' => $label,
                'category' => $category, 'amount' => $amount, 'paid' => false,
                'created_by' => $admin->id,
            ]);
        }

        $row = $this->csvFor($admin)[2];

        $this->assertSame('Ruby Rose Anudon', $row[0]);
        $this->assertSame('505.00', $row[2]);   // Basic
        $this->assertSame('350.00', $row[9]);   // Total Auth. Ded. = penalty + CA
        $this->assertSame('100.00', $row[10]);  // Penalty Lates
        $this->assertSame('250.00', $row[11]);  // CA etc
        $this->assertSame('250.00', $row[12]);  // SSS
        $this->assertSame('1.00', $row[15]);    // Days Worked
        $this->assertSame('300.00', $row[16]);  // Rice Allowance
        $this->assertSame('505.00', $row[17]);  // Daily Rate
        $this->assertSame('505.00', $row[18]);  // Gross
        // 505 gross - 100 penalty - 250 CA - 250 SSS + 300 rice = 205.00
        $this->assertSame('205.00', $row[19]);  // Net Pay
    }

    public function test_a_branch_login_exports_only_its_own_branches(): void {
        $mabini = Branch::factory()->create(['name' => 'Mabini']);
        $kanto = Branch::factory()->create(['name' => 'Kanto Craving/Brew']);
        $manager = User::factory()->create(['role' => 'branch', 'branch_id' => $mabini->id]);
        $manager->branches()->sync([$mabini->id]);

        foreach ([$mabini, $kanto] as $branch) {
            $employee = Employee::factory()->for($branch)->create(['daily_basic_rate' => 505]);
            AttendanceRecord::factory()->for($employee)->create([
                'work_date' => '2026-08-03', 'shift_start' => '08:00:00', 'shift_end' => '17:00:00',
                'clock_in' => '08:00:00', 'clock_out' => '17:00:00', 'status' => 'approved',
            ]);
        }

        $rows = $this->csvFor($manager);

        // Period label, header, one employee, TOTAL.
        $this->assertCount(4, $rows);
        $this->assertSame('Mabini', $rows[2][1]);
        $this->assertSame('TOTAL', $rows[3][0]);
    }
}
