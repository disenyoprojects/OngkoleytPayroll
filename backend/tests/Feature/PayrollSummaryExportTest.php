<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/** The payroll summary workbook: a Dashboard sheet and the client's banded summary. */
class PayrollSummaryExportTest extends TestCase {
    use RefreshDatabase;

    private ?string $workbookPath = null;

    protected function tearDown(): void {
        if ($this->workbookPath !== null && file_exists($this->workbookPath)) {
            unlink($this->workbookPath);
        }
        parent::tearDown();
    }

    private function workbookFor(User $admin): Spreadsheet {
        $content = $this->actingAs($admin)
            ->get('/api/admin/payroll/period/export?month=2026-08&period=first')
            ->assertOk()->streamedContent();

        $this->workbookPath = tempnam(sys_get_temp_dir(), 'payroll') . '.xlsx';
        file_put_contents($this->workbookPath, $content);

        return IOFactory::load($this->workbookPath);
    }

    /** @return array<string, mixed> the row's cells keyed by column letter */
    private function row(Spreadsheet $book, string $sheet, int $row, string $from = 'A', string $to = 'R'): array {
        $cells = [];
        foreach (range($from, $to) as $letter) {
            $cells[$letter] = $book->getSheetByName($sheet)->getCell($letter . $row)->getCalculatedValue();
        }

        return $cells;
    }

    public function test_the_workbook_has_a_dashboard_and_a_summary_sheet(): void {
        $book = $this->workbookFor(User::factory()->create());

        $this->assertSame(['Dashboard', 'Payroll Summary'], $book->getSheetNames());
    }

    public function test_the_header_matches_the_clients_columns(): void {
        $admin = User::factory()->create();
        $book = $this->workbookFor($admin);
        $sheet = $book->getSheetByName('Payroll Summary');

        // Row 4 carries the group bands, row 5 the columns under them.
        $this->assertSame('EMPLOYEE', $sheet->getCell('A4')->getValue());
        $this->assertSame('TIME ADJUSTMENTS', $sheet->getCell('D4')->getValue());
        $this->assertSame('DEDUCTIONS', $sheet->getCell('H4')->getValue());
        $this->assertSame('STATUTORY', $sheet->getCell('K4')->getValue());
        $this->assertSame('REFERENCE', $sheet->getCell('N4')->getValue());
        $this->assertSame('SUMMARY', $sheet->getCell('Q4')->getValue());

        $this->assertSame([
            'Employee', 'Branch', 'NSD', 'Late', 'SH', 'RH', 'UT',
            'Total Auth. Ded.', 'Penalty Lates', 'CA etc', 'SSS', 'PhilHealth', 'Pag-IBIG',
            'Days Worked', 'Rice Allowance', 'Daily Rate', 'Gross', 'Net Pay',
        ], array_values($this->row($book, 'Payroll Summary', 5)));
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

        // Row 6 is the first employee row.
        $row = $this->row($this->workbookFor($admin), 'Payroll Summary', 6);

        $this->assertSame('Ruby Rose Anudon', $row['A']);
        $this->assertEqualsWithDelta(350.00, $row['H'], 0.001);  // Total Auth. Ded. = penalty + CA
        $this->assertEqualsWithDelta(100.00, $row['I'], 0.001);  // Penalty Lates
        $this->assertEqualsWithDelta(250.00, $row['J'], 0.001);  // CA etc
        $this->assertEqualsWithDelta(250.00, $row['K'], 0.001);  // SSS
        $this->assertEqualsWithDelta(1.00, $row['N'], 0.001);    // Days Worked
        $this->assertEqualsWithDelta(300.00, $row['O'], 0.001);  // Rice Allowance
        $this->assertEqualsWithDelta(505.00, $row['P'], 0.001);  // Daily Rate
        $this->assertEqualsWithDelta(505.00, $row['Q'], 0.001);  // Gross
        // 505 gross - 100 penalty - 250 CA - 250 SSS + 300 rice = 205.00
        $this->assertEqualsWithDelta(205.00, $row['R'], 0.001);  // Net Pay
    }

    public function test_every_late_day_adds_the_flat_penalty_to_the_column(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create([
            'daily_basic_rate' => 505, 'full_name' => 'Jona Nicole Galvez',
        ]);
        // Two late days and one on time — ₱75 apiece, so ₱150.
        foreach ([
            ['2026-08-03', '08:20:00'],
            ['2026-08-04', '08:01:00'],
            ['2026-08-05', '08:00:00'],
        ] as [$date, $clockIn]) {
            AttendanceRecord::factory()->for($employee)->create([
                'work_date' => $date, 'shift_start' => '08:00:00', 'shift_end' => '17:00:00',
                'clock_in' => $clockIn, 'clock_out' => '17:00:00', 'status' => 'approved',
            ]);
        }

        $row = $this->row($this->workbookFor($admin), 'Payroll Summary', 6);

        $this->assertEqualsWithDelta(150.00, $row['I'], 0.001);  // Penalty Lates
        $this->assertEqualsWithDelta(150.00, $row['H'], 0.001);  // and it totals into Auth. Ded.
    }

    public function test_the_late_penalty_comes_off_the_take_home_pay(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create([
            'daily_basic_rate' => 505, 'full_name' => 'Ruby Rose Anudon',
        ]);
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-08-03', 'shift_start' => '08:00:00', 'shift_end' => '17:00:00',
            'clock_in' => '08:30:00', 'clock_out' => '17:00:00', 'status' => 'approved',
        ]);

        $row = $this->row($this->workbookFor($admin), 'Payroll Summary', 6);
        $hourly = 505 / 8;
        $tardiness = round(0.5 * $hourly, 2);

        $this->assertEqualsWithDelta(75.00, $row['I'], 0.001);
        // Gross is untouched — the tardiness formula still charges the half hour...
        $this->assertEqualsWithDelta(505.00, $row['Q'], 0.01);
        $this->assertEqualsWithDelta($tardiness, $row['D'], 0.01);
        // ...and the ₱75 comes off on top of it.
        $this->assertEqualsWithDelta(505.00 - $tardiness - 75.00, $row['R'], 0.01);
    }

    public function test_the_total_row_sums_the_employees(): void {
        $admin = User::factory()->create();
        $branch = Branch::factory()->create();

        foreach (['Ana Cruz', 'Ben Reyes'] as $name) {
            $employee = Employee::factory()->for($branch)->create([
                'daily_basic_rate' => 505, 'full_name' => $name,
            ]);
            AttendanceRecord::factory()->for($employee)->create([
                'work_date' => '2026-08-03', 'shift_start' => '08:00:00', 'shift_end' => '17:00:00',
                'clock_in' => '08:00:00', 'clock_out' => '17:00:00', 'status' => 'approved',
            ]);
        }

        // Two employees on rows 6-7, so the TOTAL lands on row 8.
        $row = $this->row($this->workbookFor($admin), 'Payroll Summary', 8);

        $this->assertSame('TOTAL', $row['A']);
        $this->assertEqualsWithDelta(2.00, $row['N'], 0.001);      // Days Worked
        $this->assertEqualsWithDelta(505.00, $row['P'], 0.001);    // one shared Daily Rate, not a sum
        $this->assertEqualsWithDelta(1010.00, $row['Q'], 0.001);   // Gross
        $this->assertEqualsWithDelta(1010.00, $row['R'], 0.001);   // Net Pay
    }

    public function test_the_dashboard_totals_each_branch(): void {
        $admin = User::factory()->create();
        $mabini = Branch::factory()->create(['name' => 'Mabini']);
        $bodega = Branch::factory()->create(['name' => 'Bodega']);

        foreach ([[$mabini, 'Ana Cruz'], [$mabini, 'Ben Reyes'], [$bodega, 'Cora Lim']] as [$branch, $name]) {
            $employee = Employee::factory()->for($branch)->create([
                'daily_basic_rate' => 505, 'full_name' => $name, 'short_name' => $name,
            ]);
            AttendanceRecord::factory()->for($employee)->create([
                'work_date' => '2026-08-03', 'shift_start' => '08:00:00', 'shift_end' => '17:00:00',
                'clock_in' => '08:00:00', 'clock_out' => '17:00:00', 'status' => 'approved',
            ]);
        }

        $book = $this->workbookFor($admin);
        $dashboard = $book->getSheetByName('Dashboard');

        // Headline cards: gross, net, deductions, headcount.
        $this->assertEqualsWithDelta(1515.00, $dashboard->getCell('B5')->getValue(), 0.001);
        $this->assertEqualsWithDelta(1515.00, $dashboard->getCell('E5')->getValue(), 0.001);
        $this->assertEqualsWithDelta(0.00, $dashboard->getCell('H5')->getValue(), 0.001);
        $this->assertSame(3, $dashboard->getCell('K5')->getValue());

        // Branch table: header on row 7, branches from row 8 in first-seen order.
        $this->assertSame('Branch', $dashboard->getCell('B7')->getValue());
        $this->assertSame('Mabini', $dashboard->getCell('B8')->getValue());
        $this->assertSame(2, $dashboard->getCell('D8')->getValue());              // Headcount
        $this->assertEqualsWithDelta(1010.00, $dashboard->getCell('H8')->getValue(), 0.001);  // Gross
        $this->assertEqualsWithDelta(1010.00, $dashboard->getCell('K8')->getValue(), 0.001);  // Net
        $this->assertSame('Bodega', $dashboard->getCell('B9')->getValue());
        $this->assertSame('TOTAL', $dashboard->getCell('B10')->getValue());
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

        $sheet = $this->workbookFor($manager)->getSheetByName('Payroll Summary');

        // One employee on row 6, then TOTAL — nothing from the other branch.
        $this->assertSame('Mabini', $sheet->getCell('B6')->getValue());
        $this->assertSame('TOTAL', $sheet->getCell('A7')->getValue());
        $this->assertSame(7, $sheet->getHighestDataRow());
    }
}
