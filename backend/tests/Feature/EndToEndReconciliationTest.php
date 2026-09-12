<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\PayslipController;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\User;
use App\Services\PayrollSummaryWorkbook;
use App\Services\PayslipPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One cutoff, every rule, checked against figures worked out by hand.
 *
 * The point is that the expected values below are NOT read back from the code:
 * they are arithmetic on the settings — 505.00/day, so 63.125/hour; a 1.00h
 * unpaid break; overtime at 1.25; night differential at 0.10 of the day's
 * premium rate over 22:00-06:00; DOLE premiums of 1.30 for a rest day or a
 * special holiday and 2.00 for a regular holiday. If the engine and this file
 * ever disagree, one of them is wrong and it is worth finding out which.
 *
 * The scenario deliberately covers, in one period: a plain day, lateness,
 * overtime, overtime reaching into the night window, undertime, an overbreak,
 * a rest day, a special holiday, a regular holiday, an unpaid absence, an
 * allowance, a cash advance, an authorised deduction, and the four generated
 * deductions. Then it reconciles day lines -> payslip totals -> workbook cells
 * and checks the three agree.
 */
class EndToEndReconciliationTest extends TestCase {
    use RefreshDatabase;

    private const HOURLY = 63.125;          // 505.00 / 8
    private const MONTH = '2026-08';
    private const PERIOD = 'second';        // Aug 16-31

    private ?string $workbookPath = null;

    protected function tearDown(): void {
        if ($this->workbookPath !== null && file_exists($this->workbookPath)) {
            unlink($this->workbookPath);
        }
        parent::tearDown();
    }

    private function scenario(): Employee {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create([
            'employee_code' => 'EMP-RECON', 'full_name' => 'Recon Test', 'short_name' => 'Recon',
            'daily_basic_rate' => 505, 'shift_start' => '09:00:00', 'shift_end' => '18:00:00',
        ]);

        // day | in | out | extra
        $days = [
            ['17', '09:00', '18:00', []],                                    // plain
            ['18', '09:20', '18:00', []],                                    // 20 min late
            ['19', '09:00', '20:00', []],                                    // 2h overtime
            ['20', '09:00', '23:00', []],                                    // 5h OT, 1h in the night window
            ['21', '09:00', '17:00', []],                                    // 1h undertime
            ['22', '09:00', '18:00', ['is_rest_day' => true]],               // rest day worked
            ['24', '09:00', '18:00', ['break_out' => '12:00', 'break_in' => '14:00']], // 1h overbreak
            ['25', '09:00', '18:00', ['holiday_type' => 'special']],         // special holiday worked
            ['26', '09:00', '18:00', ['absence_type' => 'absent']],          // unpaid absence
            // Aug 30 is deliberately left with no record, so the regular
            // holiday's premium is not forfeited by the day-before rule.
            ['31', '09:00', '18:00', ['holiday_type' => 'regular']],         // regular holiday worked
        ];

        foreach ($days as [$day, $in, $out, $extra]) {
            AttendanceRecord::create(array_merge([
                'employee_id' => $employee->id, 'work_date' => "2026-08-{$day}",
                'shift_start' => '09:00:00', 'shift_end' => '18:00:00',
                'clock_in' => "{$in}:00", 'clock_out' => "{$out}:00", 'status' => 'approved',
            ], $extra));
        }

        foreach ([
            ['allowance', 'Rice Allowance', 2000.00, '2026-08-16'],
            ['cash_advance', 'Cash Advance', -500.00, '2026-08-20'],
            ['deduction', 'Uniform', -300.00, '2026-08-20'],
        ] as [$category, $label, $amount, $date]) {
            PayrollAdjustment::create([
                'employee_id' => $employee->id, 'date' => $date, 'label' => $label,
                'category' => $category, 'amount' => $amount, 'paid' => false, 'created_by' => $admin->id,
            ]);
        }

        $this->actingAs($admin)->postJson(
            '/api/admin/payroll/period/statutory?month=' . self::MONTH . '&period=' . self::PERIOD
        )->assertOk();

        return $employee;
    }

    private function slip(Employee $employee): array {
        return app(PayslipController::class)->buildPayslip($employee, self::MONTH, self::PERIOD);
    }

    /**
     * Every day's pay, computed by hand. h = 63.125, 8 paid hours after the break.
     *
     * @return array<string, array{hours: float, pay: float}>
     */
    private function expectedDays(): array {
        $h = self::HOURLY;

        return [
            // 8h x h                                            = 505.00
            '2026-08-17' => ['hours' => 8.0,     'pay' => 505.00],
            // 505 - 20min tardiness (20/60 x h = 21.04)          = 483.96
            '2026-08-18' => ['hours' => 7.6667,  'pay' => 483.96],
            // 505 + 2h OT (2 x h x 1.25 = 157.81)                = 662.81
            '2026-08-19' => ['hours' => 10.0,    'pay' => 662.81],
            // 505 + 5h OT (394.53) + 1h ND (h x 0.10 = 6.31)     = 905.84
            '2026-08-20' => ['hours' => 13.0,    'pay' => 905.84],
            // 505 - 1h undertime (63.13)                         = 441.87
            '2026-08-21' => ['hours' => 7.0,     'pay' => 441.87],
            // rest day: 8h x h x 1.30                            = 656.50
            '2026-08-22' => ['hours' => 8.0,     'pay' => 656.50],
            // 505 - 1h overbreak charged back (63.13)            = 441.87
            '2026-08-24' => ['hours' => 7.0,     'pay' => 441.87],
            // special holiday: 8h x h x 1.30                     = 656.50
            '2026-08-25' => ['hours' => 8.0,     'pay' => 656.50],
            // unpaid absence                                     = 0.00
            '2026-08-26' => ['hours' => 0.0,     'pay' => 0.00],
            // regular holiday: 8h x h x 2.00                     = 1010.00
            '2026-08-31' => ['hours' => 8.0,     'pay' => 1010.00],
        ];
    }

    public function test_every_days_pay_matches_the_hand_computation(): void {
        $lines = collect($this->slip($this->scenario())['lines'])->keyBy('date');

        $this->assertCount(10, $lines, 'every attendance row should produce a line');

        foreach ($this->expectedDays() as $date => $expected) {
            $this->assertEqualsWithDelta($expected['pay'], $lines[$date]['day_pay'], 0.005, "day pay for {$date}");
            $this->assertEqualsWithDelta($expected['hours'], $lines[$date]['hours'], 0.0005, "hours for {$date}");
        }
    }

    public function test_the_period_totals_are_the_sum_of_the_days(): void {
        $slip = $this->slip($this->scenario());
        $t = $slip['totals'];

        // Worked out by hand from the day table above.
        $this->assertEqualsWithDelta(4545.00, $t['base_wage'], 0.005, '9 worked days x 505, un-premiumed');
        $this->assertEqualsWithDelta(552.34, $t['ot'], 0.005, '157.81 + 394.53');
        $this->assertEqualsWithDelta(6.31, $t['night_diff'], 0.005, '1h inside 22:00-06:00');
        $this->assertEqualsWithDelta(21.04, $t['tardiness'], 0.005, 'the single 20-minute late');
        $this->assertEqualsWithDelta(126.26, $t['undertime'], 0.005, '1h undertime + 1h overbreak');
        $this->assertEqualsWithDelta(151.50, $t['sh'], 0.005, 'special holiday uplift, 30% of 505');
        $this->assertEqualsWithDelta(505.00, $t['rh'], 0.005, 'regular holiday uplift, 100% of 505');
        $this->assertEqualsWithDelta(151.50, $t['rest_premium'], 0.005, 'rest day uplift, 30% of 505');
        $this->assertEqualsWithDelta(9.0, $slip['slip']['days_worked'], 0.005, 'the absence is not a day worked');

        // Gross is wages before the time charged back: 5353.00 + 552.34 + 6.31.
        $this->assertEqualsWithDelta(5911.65, $t['gross'], 0.005);

        // And gross must equal the premiumed basic, which is base wage plus the
        // three premium uplifts, plus overtime and night differential.
        $this->assertEqualsWithDelta(
            $t['base_wage'] + $t['sh'] + $t['rh'] + $t['rest_premium'] + $t['ot'] + $t['night_diff'],
            $t['gross'], 0.005, 'gross must decompose into its own parts',
        );
    }

    public function test_the_generated_deductions_are_right(): void {
        $t = $this->slip($this->scenario())['totals'];

        // One late day (Aug 18) x 75.00.
        $this->assertEqualsWithDelta(75.00, $t['penalty_late'], 0.005, 'one late day');

        // PhilHealth: month's basic is 4,545.00, below the 10,000 floor, so the
        // premium is read from the floor: 10,000 x 2.5% = 250.00.
        $this->assertEqualsWithDelta(250.00, $t['philhealth'], 0.005, 'income floor applies');

        // SSS basis is net earnings plus the allowance: 5,764.35 + 2,000.00 =
        // 7,764.35, which sits in the 7,750.00-8,249.99 bracket -> 400.00.
        $this->assertEqualsWithDelta(400.00, $t['sss'], 0.005);

        $this->assertEqualsWithDelta(100.00, $t['pagibig'], 0.005, 'flat per cutoff');

        $this->assertEqualsWithDelta(500.00, $t['cash_advance'], 0.005);
        $this->assertEqualsWithDelta(300.00, $t['other_authorised'], 0.005, 'the uniform');
        $this->assertEqualsWithDelta(875.00, $t['auth_deductions'], 0.005, '75 + 500 + 300');
        $this->assertEqualsWithDelta(2000.00, $t['rice_allowance'], 0.005);
    }

    public function test_the_printable_payslip_foots(): void {
        $slip = $this->slip($this->scenario());
        $earnings = collect($slip['slip']['earnings'])->pluck('amount', 'label');
        $deductions = collect($slip['slip']['deductions'])->pluck('amount', 'label');

        $this->assertEqualsWithDelta(4545.00, $earnings['Basic Wage'], 0.005);
        $this->assertEqualsWithDelta(552.34, $earnings['Overtime Pay'], 0.005);
        $this->assertEqualsWithDelta(6.31, $earnings['Night Shift Differential'], 0.005);
        $this->assertEqualsWithDelta(151.50, $earnings['Special Holiday (SH)'], 0.005);
        $this->assertEqualsWithDelta(505.00, $earnings['Regular Holiday (RH)'], 0.005);
        $this->assertEqualsWithDelta(151.50, $earnings['Rest Day Premium'], 0.005);
        $this->assertEqualsWithDelta(2000.00, $earnings['Rice Allowance'], 0.005);
        $this->assertEqualsWithDelta(7911.65, $slip['slip']['gross_earnings'], 0.005);

        $this->assertEqualsWithDelta(21.04, $deductions['Tardiness'], 0.005);
        $this->assertEqualsWithDelta(126.26, $deductions['Undertime/Overbreak'], 0.005);
        $this->assertEqualsWithDelta(500.00, $deductions['Cash Advance'], 0.005, 'keeps its own line');
        $this->assertEqualsWithDelta(400.00, $deductions['SSS'], 0.005);
        $this->assertEqualsWithDelta(250.00, $deductions['PhilHealth'], 0.005);
        $this->assertEqualsWithDelta(100.00, $deductions['Pag-IBIG'], 0.005);
        // The uniform and the late penalty print as one figure the office stands behind.
        $this->assertEqualsWithDelta(375.00, $deductions['Authorized Deduction'], 0.005, '300 uniform + 75 penalty');
        $this->assertArrayNotHasKey('Penalty Late (1 day)', $deductions, 'never itemised to the employee');
        $this->assertEqualsWithDelta(1772.30, $slip['slip']['total_deductions'], 0.005);

        // The document has to foot on its own terms.
        $this->assertEqualsWithDelta(
            $slip['slip']['gross_earnings'] - $slip['slip']['total_deductions'],
            $slip['slip']['net'], 0.005,
        );
        $this->assertEqualsWithDelta(6139.35, $slip['slip']['net'], 0.005);
    }

    /** Net on the printed slip and Net to Release are two routes to one number. */
    public function test_the_two_nets_agree(): void {
        $slip = $this->slip($this->scenario());
        $t = $slip['totals'];

        $this->assertEqualsWithDelta(375.00, $t['adjustments'], 0.005, '2000 - 500 - 300 - 75 - 400 - 250 - 100');
        $this->assertEqualsWithDelta(
            $t['gross'] - $t['tardiness'] - $t['undertime'] + $t['adjustments'],
            $t['total_salary'], 0.005,
        );
        $this->assertEqualsWithDelta(6139.35, $t['total_salary'], 0.005);
        $this->assertEqualsWithDelta(0.0, $t['paid'], 0.005, 'nothing was handed over in cash');
        $this->assertEqualsWithDelta($t['total_salary'] - $t['paid'], $t['net_to_release'], 0.005);
        $this->assertEqualsWithDelta($slip['slip']['net'], $t['net_to_release'], 0.005, 'the two nets must agree');
    }

    /** @return array<string, float|string> the employee's row keyed by column letter */
    private function workbookRow(Employee $employee): array {
        $window = PayslipPeriod::resolve(self::MONTH, self::PERIOD);
        $book = (new PayrollSummaryWorkbook([$this->slip($employee)], $window))->build();
        $sheet = $book->getSheetByName('Payroll Summary');

        $cells = [];
        foreach (range('A', 'R') as $letter) {
            $cells[$letter] = $sheet->getCell($letter . '6')->getCalculatedValue();
        }
        $book->disconnectWorksheets();

        return $cells;
    }

    public function test_the_workbook_row_carries_the_same_figures(): void {
        $row = $this->workbookRow($this->scenario());

        $this->assertSame('Recon Test', $row['A']);
        $this->assertEqualsWithDelta(6.31, $row['C'], 0.005, 'NSD');
        $this->assertEqualsWithDelta(21.04, $row['D'], 0.005, 'Late');
        $this->assertEqualsWithDelta(151.50, $row['E'], 0.005, 'SH');
        $this->assertEqualsWithDelta(505.00, $row['F'], 0.005, 'RH');
        $this->assertEqualsWithDelta(126.26, $row['G'], 0.005, 'UT');
        $this->assertEqualsWithDelta(75.00, $row['H'], 0.005, 'Penalty Lates');
        $this->assertEqualsWithDelta(800.00, $row['I'], 0.005, 'CA etc: 500 advance + 300 uniform');
        $this->assertEqualsWithDelta(875.00, $row['J'], 0.005, 'Total Auth. Ded.');
        $this->assertEqualsWithDelta(400.00, $row['K'], 0.005, 'SSS');
        $this->assertEqualsWithDelta(250.00, $row['L'], 0.005, 'PhilHealth');
        $this->assertEqualsWithDelta(100.00, $row['M'], 0.005, 'Pag-IBIG');
        $this->assertEqualsWithDelta(9.0, $row['N'], 0.005, 'Days Worked');
        $this->assertEqualsWithDelta(2000.00, $row['O'], 0.005, 'Rice Allowance');
        $this->assertEqualsWithDelta(505.00, $row['P'], 0.005, 'Daily Rate');
        $this->assertEqualsWithDelta(5911.65, $row['Q'], 0.005, 'Gross');
        $this->assertEqualsWithDelta(6139.35, $row['R'], 0.005, 'Net Pay');
    }

    /** The deduction band has to explain itself: H + I = J. */
    public function test_the_workbook_deduction_band_foots(): void {
        $row = $this->workbookRow($this->scenario());

        $this->assertEqualsWithDelta($row['J'], $row['H'] + $row['I'], 0.005);
    }

    /** Nothing may drift between the payslip and the sheet built from it. */
    public function test_the_workbook_and_the_payslip_never_disagree(): void {
        $employee = $this->scenario();
        $t = $this->slip($employee)['totals'];
        $row = $this->workbookRow($employee);

        foreach ([
            'C' => 'night_diff', 'D' => 'tardiness', 'E' => 'sh', 'F' => 'rh', 'G' => 'undertime',
            'H' => 'penalty_late', 'J' => 'auth_deductions', 'K' => 'sss', 'L' => 'philhealth',
            'M' => 'pagibig', 'O' => 'rice_allowance', 'Q' => 'gross', 'R' => 'net_to_release',
        ] as $letter => $key) {
            $this->assertEqualsWithDelta($t[$key], $row[$letter], 0.005, "column {$letter} vs totals[{$key}]");
        }

        $this->assertEqualsWithDelta($t['cash_advance'] + $t['other_authorised'], $row['I'], 0.005);
    }
}
