<?php

namespace App\Console\Commands;

use App\Http\Controllers\Admin\PayslipController;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use App\Services\PayslipPeriod;
use Illuminate\Console\Command;

/**
 * Checks that every payslip in a cutoff adds up.
 *
 * The audit command looks for suspicious encoded data. This one takes the
 * opposite view and assumes the data is what it is: it recomputes each
 * employee's earnings straight from attendance, independently of the payslip
 * builder, and then checks that what the payslip shows agrees — and that the
 * slip is internally consistent, so gross really is the sum of its earning
 * lines and net really is gross less its deductions.
 *
 * It is the difference between "is this figure believable" and "is this figure
 * right", and only the second one answers the client asking whether the
 * payslips are correct.
 *
 * Reads only.
 */
class VerifyPayslips extends Command {
    protected $signature = 'payroll:verify-payslips
                            {--month= : YYYY-MM, defaults to the current month}
                            {--period=first : first, second or whole}
                            {--all : list every employee, not just the ones with problems}';

    protected $description = 'Recompute every payslip in a cutoff and check it reconciles';

    /** Centavo rounding across many lines; anything larger is a real disagreement. */
    private const TOLERANCE = 0.02;

    private array $problems = [];

    public function handle(AttendancePayCalculator $calculator, PayslipController $payslips): int {
        $period = $this->option('period');
        if (! in_array($period, ['first', 'second', 'whole'], true)) {
            $this->error('--period must be first, second or whole.');

            return self::FAILURE;
        }

        $month = $this->option('month') ?: now()->format('Y-m');
        $window = PayslipPeriod::resolve($month, $period);
        $settings = PayrollSetting::current();

        $this->newLine();
        $this->line("<comment>VERIFY</comment> — {$window['label']}");
        $this->newLine();
        $this->line(sprintf('  %-26s %5s %10s %10s %9s %11s %11s %11s  %s',
            'EMPLOYEE', 'DAYS', 'BASIC', 'OT', 'ND', 'GROSS', 'DEDUCT', 'NET', ''));

        $checked = 0;
        $clean = 0;

        foreach (Employee::withTrashed()->with('branch')->orderBy('short_name')->get() as $employee) {
            $slip = $payslips->buildPayslip($employee, $month, $period);
            $t = $slip['totals'];
            $s = $slip['slip'];

            // Nothing earned and nothing adjusted: not a payslip, skip it.
            if (count($slip['lines']) === 0 && (float) $t['adjustments'] == 0.0) {
                continue;
            }
            $checked++;

            $before = count($this->problems);
            $this->verify($employee, $slip, $calculator, $settings, $window);
            $ok = count($this->problems) === $before;
            $clean += $ok ? 1 : 0;

            if ($ok && ! $this->option('all')) {
                continue;
            }

            $this->line(sprintf('  %-26s %5s %10s %10s %9s %11s %11s %11s  %s',
                $this->name($employee),
                rtrim(rtrim(number_format($t['days_worked'] ?? $s['days_worked'], 2), '0'), '.'),
                number_format($t['basic'], 2),
                number_format($t['ot'], 2),
                number_format($t['night_diff'], 2),
                number_format($s['gross_earnings'], 2),
                number_format($s['total_deductions'], 2),
                number_format($s['net'], 2),
                $ok ? '' : '<fg=red>CHECK</>',
            ));
        }

        return $this->report($checked, $clean);
    }

    private function verify(
        Employee $employee,
        array $slip,
        AttendancePayCalculator $calculator,
        PayrollSetting $settings,
        array $window,
    ): void {
        $t = $slip['totals'];
        $s = $slip['slip'];

        // 1. Recompute from attendance, without going through the payslip
        //    builder, and see whether the two agree. This is the check that
        //    catches the builder and the calculator drifting apart.
        $own = ['basic' => 0.0, 'ot' => 0.0, 'night_diff' => 0.0, 'tardiness' => 0.0, 'undertime' => 0.0];
        $paidDayEquivalents = 0.0;

        $records = AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('work_date', '>=', $window['from'])
            ->whereDate('work_date', '<=', $window['to'])
            ->whereNotNull('clock_out')->get();

        foreach ($records as $record) {
            $record->setRelation('employee', $employee);
            $pay = $calculator->computeForRecord($record, $settings);
            if ($pay === null) {
                continue;
            }
            foreach (array_keys($own) as $key) {
                $own[$key] += (float) $pay[$key === 'night_diff' ? 'night_diff' : $key];
            }
            if ((float) $pay['regular_hours'] > 0) {
                $paidDayEquivalents += (float) $pay['regular_hours'] / 8.0 * (float) $pay['premium_multiplier'];
            }
        }

        foreach (['basic', 'ot', 'night_diff', 'tardiness', 'undertime'] as $key) {
            $this->expect($employee, "payslip {$key}", (float) $t[$key], round($own[$key], 2),
                'recomputed straight from attendance');
        }

        // 2. The daily rate is fixed, so basic must be day-equivalents x rate.
        $rate = $employee->daily_basic_rate === null
            ? (float) $settings->daily_basic_rate
            : (float) $employee->daily_basic_rate;
        $this->expect($employee, 'basic', (float) $t['basic'], round($paidDayEquivalents * $rate, 2),
            sprintf('%s paid day-equivalents x %s', rtrim(rtrim(number_format($paidDayEquivalents, 4), '0'), '.'), number_format($rate, 2)));

        // 3. Gross is basic + overtime + night differential, nothing else.
        $this->expect($employee, 'gross', (float) $t['gross'],
            round((float) $t['basic'] + (float) $t['ot'] + (float) $t['night_diff'], 2),
            'basic + OT + night differential');

        // 4. The printed earnings and deductions must sum to their own totals.
        $this->expect($employee, 'gross earnings', (float) $s['gross_earnings'],
            round(collect($s['earnings'])->sum('amount'), 2), 'sum of the earning lines');
        $this->expect($employee, 'total deductions', (float) $s['total_deductions'],
            round(collect($s['deductions'])->sum('amount'), 2), 'sum of the deduction lines');

        // 5. Net is gross less deductions.
        $this->expect($employee, 'net', (float) $s['net'],
            round((float) $s['gross_earnings'] - (float) $s['total_deductions'], 2),
            'gross earnings less total deductions');

        // 6. The deduction band on the summary sheet has to foot: the two
        //    columns the client reads must add to the total beside them.
        $this->expect($employee, 'authorised deductions', (float) $t['auth_deductions'],
            round((float) $t['penalty_late'] + (float) $t['cash_advance'] + (float) $t['other_authorised'], 2),
            'penalty lates + cash advance + other');

        // 7. Statutory rows that were never generated. The period earned money,
        //    so a missing contribution is a payslip that is quietly short.
        if (! empty($slip['statutory_missing'])) {
            $this->problems[] = [$this->name($employee),
                'no ' . implode('/', $slip['statutory_missing']) . ' row generated for this cutoff'];
        }
    }

    private function expect(Employee $employee, string $what, float $shown, float $expected, string $how): void {
        if (abs($shown - $expected) <= self::TOLERANCE) {
            return;
        }

        $this->problems[] = [$this->name($employee), sprintf('%s shows %s, %s = %s (off by %s)',
            $what, number_format($shown, 2), $how, number_format($expected, 2),
            number_format($shown - $expected, 2))];
    }

    private function name(Employee $employee): string {
        return ($employee->short_name ?: $employee->full_name) . ' (' . $employee->employee_code . ')';
    }

    private function report(int $checked, int $clean): int {
        $this->newLine();

        if ($this->problems === []) {
            $this->info("  All {$checked} payslips reconcile.");
            $this->line('  Basic, overtime and night differential match a fresh computation from attendance;');
            $this->line('  gross is the sum of its earning lines; net is gross less its deductions.');
            $this->newLine();

            return self::SUCCESS;
        }

        $current = null;
        foreach ($this->problems as [$who, $message]) {
            if ($who !== $current) {
                $this->newLine();
                $this->line("  <comment>{$who}</comment>");
                $current = $who;
            }
            $this->line('    ' . $message);
        }

        $this->newLine();
        $this->warn(sprintf('  %d payslip(s) reconcile, %d with something to check, %d item(s) in all.',
            $clean, $checked - $clean, count($this->problems)));
        $this->newLine();

        return self::SUCCESS;
    }
}
