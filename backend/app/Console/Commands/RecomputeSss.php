<?php

namespace App\Console\Commands;

use App\Http\Controllers\Admin\StatutoryDeductionController;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\PayrollSetting;
use App\Services\PayslipPeriod;
use App\Services\PeriodEarnings;
use App\Services\SssContributionCalculator;
use Illuminate\Console\Command;

class RecomputeSss extends Command
{
    protected $signature = 'payroll:recompute-sss
        {--exclude=* : Employee code(s) or id(s) to leave untouched}
        {--month=* : Limit to these months, format YYYY-MM (default: every month found)}
        {--whole : Treat an end-of-month row as a whole-month period rather than the 16th-to-end cutoff}
        {--apply : Write the corrections (without this the command only previews them)}';

    protected $description = 'Recompute auto-generated SSS deductions against the current bracket rules';

    public function __construct(
        private PeriodEarnings $earnings,
        private SssContributionCalculator $sss,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $settings = PayrollSetting::current();
        $excluded = $this->resolveExcluded();
        $months = $this->option('month');

        // Only rows this generator wrote — a hand-entered SSS line is never
        // touched, the same rule the Generate Statutory button follows.
        $rows = PayrollAdjustment::where('category', 'sss')
            ->where('reason', StatutoryDeductionController::AUTO_REASON)
            ->when($excluded, fn ($q) => $q->whereNotIn('employee_id', $excluded))
            ->orderBy('employee_id')->orderBy('date')
            ->get();

        $changes = [];
        $unchanged = 0;
        $orphaned = 0;

        foreach ($rows as $row) {
            $month = $row->date->format('Y-m');
            if ($months && ! in_array($month, $months, true)) {
                continue;
            }

            $employee = Employee::withTrashed()->find($row->employee_id);
            if (! $employee) {
                $orphaned++;
                continue;
            }

            $period = $this->periodFor($row->date->day, $row->date->daysInMonth);
            $window = PayslipPeriod::resolve($month, $period);
            $net = $this->earnings->sum($employee, $window, $settings, 'total');
            $correct = -round($this->sss->employeeShareFor($net), 2);
            $current = round((float) $row->amount, 2);

            if ($current === $correct) {
                $unchanged++;
                continue;
            }

            $changes[] = [
                'row' => $row,
                'correct' => $correct,
                'line' => [
                    $employee->employee_code ?? $employee->id,
                    $employee->short_name ?? $employee->full_name,
                    $window['label'] ?? "{$window['from']} to {$window['to']}",
                    number_format($net, 2),
                    number_format($current, 2),
                    number_format($correct, 2),
                ],
            ];
        }

        if ($orphaned) {
            $this->warn("{$orphaned} row(s) point at an employee that no longer exists — skipped.");
        }

        if (! $changes) {
            $this->info("Nothing to correct. {$unchanged} SSS row(s) already match the current rules.");

            return self::SUCCESS;
        }

        $this->table(
            ['Code', 'Employee', 'Period', 'Net earnings', 'Current SSS', 'Correct SSS'],
            array_column($changes, 'line'),
        );

        if (! $this->option('apply')) {
            $this->warn(count($changes) . ' row(s) would change, ' . $unchanged . ' already correct. Nothing written — re-run with --apply.');

            return self::SUCCESS;
        }

        foreach ($changes as $change) {
            $change['row']->update(['amount' => $change['correct']]);
        }

        $this->info('Corrected ' . count($changes) . " row(s). {$unchanged} were already right.");

        return self::SUCCESS;
    }

    /** Employee ids for the --exclude codes/ids given. Fails loudly on a code that matches nobody. */
    private function resolveExcluded(): array
    {
        $ids = [];
        foreach ($this->option('exclude') as $needle) {
            $employee = Employee::withTrashed()
                ->where('employee_code', $needle)
                ->orWhere('id', is_numeric($needle) ? (int) $needle : 0)
                ->first();

            if (! $employee) {
                $this->warn("No employee matches --exclude={$needle} — nothing excluded for it.");
                continue;
            }

            $ids[] = $employee->id;
            $this->line("Excluding {$employee->full_name} ({$employee->employee_code}).");
        }

        return $ids;
    }

    /**
     * Which cutoff a row belongs to, from the date the generator stamped on it
     * (always the window's last day). A 15th is the first cutoff; an
     * end-of-month date is the second cutoff, or the whole month under --whole.
     */
    private function periodFor(int $day, int $daysInMonth): string
    {
        if ($day === 15) {
            return 'first';
        }

        return $day === $daysInMonth && $this->option('whole') ? 'whole' : 'second';
    }
}
