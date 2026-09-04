<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\PayrollSetting;
use App\Services\LatePenaltyCalculator;
use App\Services\PayslipPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Finds the late-penalty rows left behind by the generator removed in 3fb2e53.
 *
 * That generator wrote one row per late day and was taken out, but reverting
 * code does not delete data, so its rows are still on payslips — typed
 * penalty_late and labelled "Penalty Late (Aug 7)" — where the current
 * generator writes one row per cutoff typed as an Authorized Deduction.
 *
 * The current generator will not touch a period holding these: it treats them
 * as somebody else's and skips, which is what stops the same days being charged
 * twice. So they have to be removed by hand before a period can be regenerated.
 *
 * Lists by default and deletes nothing. Deleting needs --delete, and even then
 * it prints the rows and asks first.
 */
class ListOldLatePenalties extends Command {
    private const OLD_REASON = 'Auto-generated late penalty';

    protected $signature = 'payroll:old-late-penalties
                            {--month= : Only this month, as YYYY-MM}
                            {--delete : Actually remove the rows, after confirming}';

    protected $description = 'List (or remove) late-penalty rows left by the removed generator';

    public function handle(LatePenaltyCalculator $penalties): int {
        $query = PayrollAdjustment::with('employee')->where('reason', self::OLD_REASON);

        if ($month = $this->option('month')) {
            if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
                $this->error('--month must look like 2026-08.');

                return self::FAILURE;
            }
            $window = PayslipPeriod::resolve($month, 'whole');
            $query->whereDate('date', '>=', $window['from'])->whereDate('date', '<=', $window['to']);
        }

        $rows = $query->orderBy('employee_id')->orderBy('date')->get();

        if ($rows->isEmpty()) {
            $this->info('No leftover rows found. Nothing to do.');

            return self::SUCCESS;
        }

        $settings = PayrollSetting::current();
        $grandOld = 0.0;
        $grandNew = 0.0;

        // Grouped by the cutoff each row falls in, because that is the unit the
        // current generator would rewrite — and the unit the office pays on.
        foreach ($rows->groupBy(fn ($row) => $row->employee_id . '|' . $this->cutoffKey($row->date)) as $key => $group) {
            [$employeeId, $cutoff] = explode('|', $key);
            [$month, $period] = explode(':', $cutoff);
            $employee = $group->first()->employee ?? Employee::withTrashed()->find($employeeId);
            $window = PayslipPeriod::resolve($month, $period);

            $oldTotal = round(abs((float) $group->sum('amount')), 2);
            $grandOld += $oldTotal;

            $this->newLine();
            $this->line(sprintf(
                '<comment>%s</comment> — %s (%d %s)',
                $employee?->short_name ?? "employee #{$employeeId}",
                $window['label'],
                $group->count(),
                $group->count() === 1 ? 'row' : 'rows',
            ));

            foreach ($group as $row) {
                $this->line(sprintf('    %s  %-28s %10s',
                    $row->date->format('Y-m-d'), $row->label, number_format((float) $row->amount, 2)));
            }

            // What pressing Generate Deductions would write once these are gone,
            // so the difference is visible before anything is removed.
            $newTotal = $employee
                ? $penalties->amountFor($employee, $window, $settings)
                : 0.0;
            $grandNew += $newTotal;

            $this->line(sprintf('    <info>old total %s  ->  new generator would write %s%s</info>',
                number_format($oldTotal, 2),
                number_format($newTotal, 2),
                abs($newTotal - $oldTotal) > 0.005 ? '   *** DIFFERS ***' : ''));
        }

        $this->newLine();
        $this->line(sprintf('%d rows across %d cutoffs. Old total %s, new generator would write %s.',
            $rows->count(),
            $rows->groupBy(fn ($r) => $r->employee_id . '|' . $this->cutoffKey($r->date))->count(),
            number_format($grandOld, 2),
            number_format($grandNew, 2)));

        if (! $this->option('delete')) {
            $this->newLine();
            $this->info('Nothing was changed. Re-run with --delete to remove these rows.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn('This permanently deletes the rows listed above. Employees already paid on a');
        $this->warn('closed period keep the same money either way — only the presentation changes.');

        if (! $this->confirm("Delete {$rows->count()} rows?", false)) {
            $this->info('Left alone.');

            return self::SUCCESS;
        }

        $deleted = PayrollAdjustment::whereIn('id', $rows->pluck('id'))->delete();
        $this->info("Deleted {$deleted} rows. Press Generate Deductions on those cutoffs to rewrite them.");

        return self::SUCCESS;
    }

    /** "2026-08:first" — the cutoff a dated row belongs to. */
    private function cutoffKey(Carbon $date): string {
        return $date->format('Y-m') . ':' . ($date->day <= 15 ? 'first' : 'second');
    }
}
