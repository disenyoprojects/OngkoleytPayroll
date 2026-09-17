<?php

namespace App\Console\Commands;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use App\Services\PayslipPeriod;
use Illuminate\Console\Command;

/**
 * Shows how a cutoff's earnings were arrived at, day by day.
 *
 * A payslip gives totals and the day table gives clock times, and neither
 * shows the second clock pair — so when overtime or night differential looks
 * wrong there is nothing to point at, and the figure has to be reverse-
 * engineered from the total. That has been done three times this week.
 *
 * This prints, per day: the shift the day was judged against, both clock
 * pairs, the hours split into regular, overtime and night, and what each
 * earned. The two things that most often explain a surprising total are the
 * shift span — a day scheduled ten hours pays nine after the break, not eight
 * — and an OT pair running past 22:00.
 *
 * Reads only.
 */
class ExplainPayslip extends Command {
    protected $signature = 'payroll:explain-payslip
                            {employee : employee code, or part of a name}
                            {--month= : YYYY-MM, defaults to the current month}
                            {--period=first : first, second or whole}';

    protected $description = "Show how a cutoff's basic, overtime and night differential were computed";

    public function handle(AttendancePayCalculator $calculator): int {
        $period = $this->option('period');
        if (! in_array($period, ['first', 'second', 'whole'], true)) {
            $this->error('--period must be first, second or whole.');

            return self::FAILURE;
        }

        $needle = $this->argument('employee');
        $employee = Employee::withTrashed()
            ->where('employee_code', $needle)
            ->orWhere('full_name', 'like', "%{$needle}%")
            ->orWhere('short_name', 'like', "%{$needle}%")
            ->first();

        if (! $employee) {
            $this->error("No employee matched \"{$needle}\".");

            return self::FAILURE;
        }

        $month = $this->option('month') ?: now()->format('Y-m');
        $window = PayslipPeriod::resolve($month, $period);
        $settings = PayrollSetting::current();
        $rate = $employee->daily_basic_rate === null
            ? (float) $settings->daily_basic_rate
            : (float) $employee->daily_basic_rate;
        $hourly = $rate / 8;

        $this->newLine();
        $this->line("<comment>{$employee->full_name}</comment> ({$employee->employee_code}) — {$window['label']}");
        $this->line(sprintf('daily rate %s, so %s an hour; a day is worth its scheduled hours less the %sh break',
            number_format($rate, 2), number_format($hourly, 4), rtrim(rtrim((string) $settings->unpaid_break_hours, '0'), '.')));
        $this->newLine();

        $records = AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('work_date', '>=', $window['from'])
            ->whereDate('work_date', '<=', $window['to'])
            ->whereNotNull('clock_out')
            ->orderBy('work_date')->get();

        if ($records->isEmpty()) {
            $this->warn('No attendance in this window.');

            return self::SUCCESS;
        }

        $this->line(sprintf('  %-11s %-13s %-13s %-13s %6s %6s %6s %10s %9s %8s  %s',
            'DATE', 'SHIFT', 'CLOCK', 'OT PAIR', 'SCHED', 'REG', 'OT', 'BASIC', 'OT PAY', 'ND', 'DAY'));

        $regHours = $otHours = $ndHours = 0.0;
        $basic = $ot = $nd = 0.0;
        $oddShifts = [];

        foreach ($records as $record) {
            $record->setRelation('employee', $employee);
            $pay = $calculator->computeForRecord($record, $settings);
            if ($pay === null) {
                continue;
            }

            $shiftFrom = substr((string) $record->shift_start, 0, 5);
            $shiftTo = substr((string) $record->shift_end, 0, 5);
            $scheduled = $this->spanHours($shiftFrom, $shiftTo);

            // A day scheduled longer than the usual nine hours pays more base
            // wage, which is the commonest reason a period beats days x rate.
            if (abs($scheduled - 9.0) > 0.001) {
                $oddShifts[] = [$record->work_date->format('Y-m-d'), $shiftFrom, $shiftTo, $scheduled, $pay['regular_hours']];
            }

            $regHours += (float) $pay['regular_hours'];
            $otHours += (float) $pay['ot_hours'];
            $ndHours += (float) $pay['night_diff_hours'];
            $basic += (float) $pay['basic'];
            $ot += (float) $pay['ot'];
            $nd += (float) $pay['night_diff'];

            $this->line(sprintf('  %-11s %-13s %-13s %-13s %6.2f %6.2f %6.2f %10s %9s %8s  %s',
                $record->work_date->format('Y-m-d'),
                "{$shiftFrom}-{$shiftTo}",
                substr((string) $record->clock_in, 0, 5) . '-' . substr((string) $record->clock_out, 0, 5),
                $record->ot_in ? substr((string) $record->ot_in, 0, 5) . '-' . substr((string) $record->ot_out, 0, 5) : '—',
                $scheduled,
                $pay['regular_hours'],
                $pay['ot_hours'],
                number_format($pay['basic'], 2),
                number_format($pay['ot'], 2),
                number_format($pay['night_diff'], 2),
                $pay['premium_label'] === 'Ordinary' ? '' : $pay['premium_label'],
            ));
        }

        $this->newLine();
        $this->line('  <info>TOTALS</info>');
        $this->line(sprintf('    regular  %8.2f h   basic     %12s', $regHours, number_format($basic, 2)));
        $this->line(sprintf('    overtime %8.2f h   overtime  %12s', $otHours, number_format($ot, 2)));
        $this->line(sprintf('    night    %8.2f h   night dif %12s', $ndHours, number_format($nd, 2)));

        if ($oddShifts) {
            $this->newLine();
            $this->warn('  Not the usual 9h shift, so not ' . number_format($rate, 2) . ' for the day:');
            foreach ($oddShifts as [$date, $from, $to, $scheduled, $regular]) {
                $this->line(sprintf('    %s  %s-%s  %.2fh sched', $date, $from, $to, $scheduled));
                $this->line(sprintf('      = %.2f paid hours = %s', $regular, number_format($regular * $hourly, 2)));
            }
        }

        return self::SUCCESS;
    }

    /** Hours between two H:i times, rolling past midnight the way the calculator does. */
    private function spanHours(string $from, string $to): float {
        [$fh, $fm] = array_map('intval', explode(':', $from));
        [$th, $tm] = array_map('intval', explode(':', $to));
        $start = $fh * 60 + $fm;
        $end = $th * 60 + $tm;
        if ($end <= $start) {
            $end += 24 * 60;
        }

        return ($end - $start) / 60.0;
    }
}
