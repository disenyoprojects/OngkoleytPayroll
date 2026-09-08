<?php

namespace App\Console\Commands;

use App\Http\Controllers\Admin\PayslipController;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Services\PayslipPeriod;
use Illuminate\Console\Command;

/**
 * Itemises a cutoff's deduction band: every adjustment row, which summary
 * column it lands in, and the totals it produces.
 *
 * The workbook's bands are sums, and when one disagrees with what somebody
 * expects there is no way to see which rows made it up — the sheet gives a
 * figure and the payslip gives a list, and reconciling them is done by eye.
 * This prints both sides at once so "where did that number come from" has an
 * answer that takes a command rather than an afternoon.
 *
 * Reads only.
 */
class ExplainDeductions extends Command {
    protected $signature = 'payroll:explain-deductions
                            {employee : employee code, or part of a name}
                            {--month= : YYYY-MM, defaults to the current month}
                            {--period=first : first, second or whole}';

    protected $description = 'Show which adjustment rows make up a cutoff\'s deduction columns';

    public function handle(PayslipController $payslips): int {
        $needle = $this->argument('employee');
        $month = $this->option('month') ?: now()->format('Y-m');
        $period = $this->option('period');

        if (! in_array($period, ['first', 'second', 'whole'], true)) {
            $this->error('--period must be first, second or whole.');

            return self::FAILURE;
        }

        $employee = Employee::withTrashed()
            ->where('employee_code', $needle)
            ->orWhere('full_name', 'like', "%{$needle}%")
            ->orWhere('short_name', 'like', "%{$needle}%")
            ->first();

        if (! $employee) {
            $this->error("No employee matched \"{$needle}\".");

            return self::FAILURE;
        }

        $window = PayslipPeriod::resolve($month, $period);
        $rows = PayrollAdjustment::where('employee_id', $employee->id)
            ->whereDate('date', '>=', $window['from'])->whereDate('date', '<=', $window['to'])
            ->orderBy('date')->get();

        $this->newLine();
        $this->line("<comment>{$employee->full_name}</comment> ({$employee->employee_code}) — {$window['label']}");
        $this->line("{$window['from']} to {$window['to']}");
        $this->newLine();

        if ($rows->isEmpty()) {
            $this->warn('No adjustment rows in this window at all.');
        } else {
            $this->line(sprintf('  %-28s %-12s %-20s %12s   %s', 'LABEL', 'DATE', 'TYPE', 'AMOUNT', 'LANDS IN'));
            foreach ($rows as $row) {
                $this->line(sprintf('  %-28s %-12s %-20s %12s   %s',
                    mb_strimwidth((string) $row->label, 0, 28, '…'),
                    $row->date->format('Y-m-d'),
                    $row->category,
                    number_format((float) $row->amount, 2),
                    $this->columnFor($row)));
            }
        }

        // The same totals the workbook writes, so the two can be compared
        // without exporting anything.
        $t = $payslips->buildPayslip($employee, $month, $period)['totals'];
        $caEtc = round($t['cash_advance'] + $t['other_authorised'], 2);

        $this->newLine();
        $this->line('  <info>DEDUCTION BAND AS THE WORKBOOK WRITES IT</info>');
        $this->line(sprintf('    H  Penalty Lates      %12s', number_format($t['penalty_late'], 2)));
        $this->line(sprintf('    I  CA etc             %12s   (cash advance %s + other authorised %s)',
            number_format($caEtc, 2), number_format($t['cash_advance'], 2), number_format($t['other_authorised'], 2)));
        $this->line(sprintf('    J  Total Auth. Ded.   %12s', number_format($t['auth_deductions'], 2)));

        $foots = abs(($t['penalty_late'] + $caEtc) - $t['auth_deductions']) < 0.005;
        $this->line('    ' . ($foots ? '<info>H + I = J, the band foots</info>' : '<error>H + I does not equal J</error>'));

        $this->newLine();
        $this->line(sprintf('    SSS %s   PhilHealth %s   Pag-IBIG %s',
            number_format($t['sss'], 2), number_format($t['philhealth'], 2), number_format($t['pagibig'], 2)));

        return self::SUCCESS;
    }

    /** Where this row shows up on the summary sheet. */
    private function columnFor(PayrollAdjustment $row): string {
        $isLateLabel = preg_match('/\blate|\bpenalt/i', (string) $row->label);

        return match (true) {
            $row->category === 'penalty_late' => 'H  Penalty Lates',
            $row->category === 'deduction' && $isLateLabel => 'H  Penalty Lates (by label)',
            $row->category === 'deduction' => 'I  CA etc (other authorised)',
            $row->category === 'cash_advance' => 'I  CA etc (cash advance)',
            $row->category === 'sss' => 'K  SSS',
            $row->category === 'philhealth' => 'L  PhilHealth',
            $row->category === 'pagibig' => 'M  Pag-IBIG',
            $row->category === 'rice_allowance' => 'O  Rice Allowance',
            $row->category === 'allowance' && preg_match('/\brice\b/i', (string) $row->label) => 'O  Rice Allowance (by label)',
            (float) $row->amount > 0 => 'earnings, not a deduction',
            default => 'not in the deduction band',
        };
    }
}
