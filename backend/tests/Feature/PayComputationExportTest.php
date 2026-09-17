<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * The workbook sent out for confirmation.
 *
 * It exists so a figure can be checked rather than taken on trust, which only
 * works if the money cells really are formulas over visible inputs. So this
 * test opens the produced file and evaluates them, instead of asserting that
 * bytes were written.
 */
class PayComputationExportTest extends TestCase {
    use RefreshDatabase;

    private function employee(): Employee {
        return Employee::factory()->for(Branch::factory())->create([
            'employee_code' => 'EMP-0016', 'full_name' => 'Christopher T. Navarro',
            'short_name' => 'Christopher', 'daily_basic_rate' => 505,
            'shift_start' => '10:00:00', 'shift_end' => '19:00:00',
        ]);
    }

    private function download(string $query = 'month=2026-09&period=first'): Spreadsheet {
        $response = $this->actingAs(User::factory()->create())
            ->get("/api/admin/payroll/period/computation?{$query}");
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'calc') . '.xlsx';
        file_put_contents($path, $response->streamedContent());
        $book = IOFactory::load($path);
        unlink($path);

        return $book;
    }

    /**
     * Christopher's 2026-09-08, the day that started all this: a full shift,
     * then an overtime pair running to 07:47 the next morning.
     */
    public function test_the_money_cells_are_formulas_that_evaluate_to_the_payslip_figures(): void {
        $employee = $this->employee();
        AttendanceRecord::create([
            'employee_id' => $employee->id, 'work_date' => '2026-09-08',
            'shift_start' => '10:00:00', 'shift_end' => '19:00:00',
            'clock_in' => '09:53:00', 'clock_out' => '19:59:00',
            'ot_in' => '20:06:00', 'ot_out' => '07:47:00', 'status' => 'approved',
        ]);

        $sheet = $this->download()->getSheetByName('Computation');

        // Row 6 is the first day; row 5 carries the headings.
        $this->assertSame('=J6/8', $sheet->getCell('K6')->getValue());
        $this->assertSame('=ROUND(N6*M6,2)', $sheet->getCell('O6')->getValue());
        $this->assertSame('=ROUND(P6*Q6,2)', $sheet->getCell('R6')->getValue());
        $this->assertSame('=ROUND(S6*T6,2)', $sheet->getCell('U6')->getValue());

        // ...and they compute the figures the payslip shows.
        $this->assertEqualsWithDelta(63.125, $sheet->getCell('K6')->getCalculatedValue(), 0.0001);
        $this->assertEqualsWithDelta(505.00, $sheet->getCell('O6')->getCalculatedValue(), 0.01);
        $this->assertEqualsWithDelta(999.48, $sheet->getCell('R6')->getCalculatedValue(), 0.01);
        $this->assertEqualsWithDelta(50.50, $sheet->getCell('U6')->getCalculatedValue(), 0.01);
        $this->assertEqualsWithDelta(1554.98, $sheet->getCell('AA6')->getCalculatedValue(), 0.01);
    }

    /** The second clock pair is the point of the sheet, so it has to be on it. */
    public function test_the_overtime_pair_is_shown_and_an_implausible_one_is_flagged(): void {
        $employee = $this->employee();
        AttendanceRecord::create([
            'employee_id' => $employee->id, 'work_date' => '2026-09-08',
            'shift_start' => '10:00:00', 'shift_end' => '19:00:00',
            'clock_in' => '09:53:00', 'clock_out' => '19:59:00',
            'ot_in' => '20:06:00', 'ot_out' => '07:47:00', 'status' => 'approved',
        ]);

        $sheet = $this->download()->getSheetByName('Computation');

        $this->assertSame('20:06', $sheet->getCell('H6')->getValue());
        $this->assertSame('07:47', $sheet->getCell('I6')->getValue());

        // 12.67 hours of overtime in one day is the shape of a mis-keyed AM/PM,
        // so the pair carries a note asking for it to be confirmed.
        $this->assertStringContainsString(
            'confirming the OT Out time',
            $sheet->getComment('H6')->getText()->getPlainText(),
        );
    }

    /** An ordinary day carries no comment, or the flag would mean nothing. */
    public function test_an_ordinary_day_is_not_flagged(): void {
        $employee = $this->employee();
        AttendanceRecord::create([
            'employee_id' => $employee->id, 'work_date' => '2026-09-09',
            'shift_start' => '10:00:00', 'shift_end' => '19:00:00',
            'clock_in' => '10:00:00', 'clock_out' => '19:53:00', 'status' => 'approved',
        ]);

        $sheet = $this->download()->getSheetByName('Computation');

        $this->assertSame('', $sheet->getComment('H6')->getText()->getPlainText());
        $this->assertEqualsWithDelta(574.70, $sheet->getCell('AA6')->getCalculatedValue(), 0.01);
    }

    /** Each employee is subtotalled, so the sheet ties to their payslip. */
    public function test_each_employee_gets_a_subtotal_row(): void {
        $employee = $this->employee();
        foreach (['2026-09-01', '2026-09-02'] as $date) {
            AttendanceRecord::create([
                'employee_id' => $employee->id, 'work_date' => $date,
                'shift_start' => '10:00:00', 'shift_end' => '19:00:00',
                'clock_in' => '10:00:00', 'clock_out' => '19:00:00', 'status' => 'approved',
            ]);
        }

        $sheet = $this->download()->getSheetByName('Computation');

        $this->assertSame('TOTAL', $sheet->getCell('A8')->getValue());
        $this->assertSame('2 day(s)', $sheet->getCell('D8')->getValue());
        $this->assertSame('=SUM(AA6:AA7)', $sheet->getCell('AA8')->getValue());
        $this->assertEqualsWithDelta(1010.00, $sheet->getCell('AA8')->getCalculatedValue(), 0.01);
    }

    /** One employee can be exported alone, for a single query. */
    public function test_it_can_be_narrowed_to_one_employee(): void {
        $employee = $this->employee();
        $other = Employee::factory()->for($employee->branch)->create([
            'employee_code' => 'EMP-0020', 'short_name' => 'Ruby', 'daily_basic_rate' => 505,
        ]);
        foreach ([$employee, $other] as $person) {
            AttendanceRecord::create([
                'employee_id' => $person->id, 'work_date' => '2026-09-08',
                'shift_start' => '10:00:00', 'shift_end' => '19:00:00',
                'clock_in' => '10:00:00', 'clock_out' => '19:00:00', 'status' => 'approved',
            ]);
        }

        $sheet = $this->download('month=2026-09&period=first&employee=EMP-0016')
            ->getSheetByName('Computation');

        $this->assertSame('Christopher', $sheet->getCell('A6')->getValue());
        $this->assertSame('TOTAL', $sheet->getCell('A7')->getValue());
    }

    /** The rules the figures were computed under travel with them. */
    public function test_the_workbook_states_the_rules_it_used(): void {
        $employee = $this->employee();
        AttendanceRecord::create([
            'employee_id' => $employee->id, 'work_date' => '2026-09-08',
            'shift_start' => '10:00:00', 'shift_end' => '19:00:00',
            'clock_in' => '10:00:00', 'clock_out' => '19:00:00', 'status' => 'approved',
        ]);

        $rules = $this->download()->getSheetByName('How It Is Computed');
        $text = '';
        foreach ($rules->getRowIterator() as $line) {
            foreach ($line->getCellIterator() as $cell) {
                $text .= ' ' . $cell->getValue();
            }
        }

        $this->assertStringContainsString('daily rate is FIXED', $text);
        $this->assertStringContainsString('SCHEDULED SHIFT END', $text);
        $this->assertStringContainsString('22:00 and 06:00', $text);
    }
}
