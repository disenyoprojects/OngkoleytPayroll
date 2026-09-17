<?php

namespace App\Console\Commands;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use App\Services\PayslipPeriod;
use Illuminate\Console\Command;

/**
 * Checks a whole cutoff for the faults that have actually occurred here.
 *
 * Every payslip queried by the client over the last month traced to encoded
 * data rather than to the computation — a break longer than the shift it sat
 * in, a clock-out keyed AM instead of PM, an overtime pair running eleven
 * hours overnight, a day with clock times that pays nothing. Each was found by
 * hand, one payslip at a time, after someone complained.
 *
 * So the checks below are not generic validation: each one is a bug that has
 * already reached a payslip, written down so the next instance is found before
 * the client finds it.
 *
 * Reads only. Nothing here writes to attendance, adjustments or payroll.
 */
class AuditPeriod extends Command {
    protected $signature = 'payroll:audit-period
                            {--month= : YYYY-MM, defaults to the current month}
                            {--period=first : first, second or whole}
                            {--employee= : limit to one employee code}';

    protected $description = 'Check every payslip in a cutoff for encoding faults and computation mismatches';

    /** A day this long is not a day; it is a mis-keyed clock. */
    private const IMPLAUSIBLE_DAY_HOURS = 20.0;
    private const IMPLAUSIBLE_OT_HOURS = 8.0;
    private const IMPLAUSIBLE_SPAN_HOURS = 16.0;

    private array $findings = [];

    public function handle(AttendancePayCalculator $calculator): int {
        $period = $this->option('period');
        if (! in_array($period, ['first', 'second', 'whole'], true)) {
            $this->error('--period must be first, second or whole.');

            return self::FAILURE;
        }

        $month = $this->option('month') ?: now()->format('Y-m');
        $window = PayslipPeriod::resolve($month, $period);
        $settings = PayrollSetting::current();
        $rate = (float) $settings->daily_basic_rate;

        $employees = Employee::withTrashed()
            ->when($this->option('employee'), fn ($q) => $q->where('employee_code', $this->option('employee')))
            ->orderBy('short_name')->get();

        $this->newLine();
        $this->line("<comment>AUDIT</comment> — {$window['label']}");
        $this->line(sprintf('  %d employees, daily rate %s', $employees->count(), number_format($rate, 2)));
        $this->newLine();

        $checked = 0;
        $people = 0;

        foreach ($employees as $employee) {
            $records = AttendanceRecord::where('employee_id', $employee->id)
                ->whereDate('work_date', '>=', $window['from'])
                ->whereDate('work_date', '<=', $window['to'])
                ->orderBy('work_date')->get();

            if ($records->isEmpty()) {
                continue;
            }
            $people++;

            $paidDays = 0.0;
            $basic = 0.0;

            foreach ($records as $record) {
                $checked++;
                $record->setRelation('employee', $employee);
                $pay = $calculator->computeForRecord($record, $settings);

                $this->checkRecord($employee, $record, $pay);

                if ($pay === null) {
                    continue;
                }
                $basic += (float) $pay['basic'];
                if ((float) $pay['regular_hours'] > 0) {
                    $paidDays += (float) $pay['regular_hours'] / 8.0 * (float) $pay['premium_multiplier'];
                }
            }

            // The fixed daily rate means basic must be days x rate exactly. If
            // it is not, the rate change did not reach this person's records.
            $expected = round($paidDays * $rate, 2);
            if (abs($expected - round($basic, 2)) > 0.02) {
                $this->flag($employee, '', sprintf(
                    'basic %s but %s paid day-equivalents x %s = %s',
                    number_format($basic, 2), rtrim(rtrim(number_format($paidDays, 4), '0'), '.'),
                    number_format($rate, 2), number_format($expected, 2),
                ));
            }
        }

        return $this->report($checked, $people);
    }

    private function checkRecord(Employee $employee, AttendanceRecord $record, ?array $pay): void {
        $date = $record->work_date?->format('Y-m-d') ?? '?';
        $in = $this->hhmm($record->clock_in);
        $out = $this->hhmm($record->clock_out);

        // 1. Clocked in and never out. The day computes as nothing and vanishes
        //    from the payslip silently.
        if ($record->clock_in && ! $record->clock_out) {
            $this->flag($employee, $date, 'clocked in at ' . $in . ' and never clocked out — day pays nothing');

            return;
        }

        // An unworked regular holiday has no clock pair at all, so the
        // calculator returns null and the day never reaches the checks below.
        // That is exactly the case the client's rule says should be paid, so it
        // has to be caught here rather than after the null guard.
        if ($pay === null) {
            if ($record->holiday_type === 'regular') {
                $this->flag($employee, $date, 'regular holiday not worked, pays 0.00 — the client\'s rule pays the full daily rate when the day before was worked');
            }

            return;
        }

        // 2. Clock times present but the day pays nothing. Christopher's
        //    2026-09-10: 08:00-17:00 encoded, PHP 0.00 paid, because the day
        //    carries a no-pay absence type. One of the two is wrong.
        if ($record->clock_in && $record->clock_out && (float) $pay['total'] == 0.0) {
            $this->flag($employee, $date, sprintf(
                'worked %s-%s but pays 0.00 — tagged "%s"',
                $in, $out, $record->absence_type ?: 'no absence type',
            ));
        }

        // 3. An overtime pair longer than a whole shift. Christopher's
        //    2026-09-08 ran 20:06-07:47 — eleven hours forty, overnight, worth
        //    PHP 1,049.98, on a day he had already stood a full shift.
        if ((float) $pay['ot_hours'] > self::IMPLAUSIBLE_OT_HOURS) {
            $this->flag($employee, $date, sprintf(
                '%.2f h overtime%s — check the OT Out is PM/AM as intended',
                $pay['ot_hours'],
                $record->ot_in ? ' (OT pair ' . $this->hhmm($record->ot_in) . '-' . $this->hhmm($record->ot_out) . ')' : '',
            ));
        }

        // 4. More hours than exist in a working day.
        if ((float) $pay['total_hours'] > self::IMPLAUSIBLE_DAY_HOURS) {
            $this->flag($employee, $date, sprintf('%.2f hours in one day', $pay['total_hours']));
        }

        // 5. A clock span longer than sixteen hours. Ruby's 2026-09-12 clock-out
        //    read 08:21 where 20:21 was meant, and the day reconciled only once
        //    it was read as PM.
        $span = $this->spanHours($record->clock_in, $record->clock_out);
        if ($span !== null && $span > self::IMPLAUSIBLE_SPAN_HOURS) {
            $this->flag($employee, $date, sprintf('clock span %s-%s is %.2f h', $in, $out, $span));
        }

        // 6. A break longer than the attendance it sits inside. Ruby's
        //    2026-08-17 recorded a 12h35m break on a 9h34m day and charged
        //    PHP 731.20 of overbreak for it.
        $break = $this->spanHours($record->break_out, $record->break_in);
        if ($break !== null && $span !== null && $break > $span) {
            $this->flag($employee, $date, sprintf(
                'break %s-%s is %.2f h, longer than the %.2f h day it sits in',
                $this->hhmm($record->break_out), $this->hhmm($record->break_in), $break, $span,
            ));
        }
        if ($break !== null && $break < 0) {
            $this->flag($employee, $date, 'break in is earlier than break out');
        }

        // 7. Night differential on a day that never reached 22:00. Queried by
        //    the client once already ("but jona didnt work past 10pm").
        if ((float) $pay['night_diff_hours'] > 0 && ! $record->ot_in) {
            $endMin = $this->minutes($record->clock_out);
            $startMin = $this->minutes($record->clock_in);
            if ($endMin !== null && $startMin !== null && $endMin > $startMin
                && $endMin <= 22 * 60 && $startMin >= 6 * 60) {
                $this->flag($employee, $date, sprintf(
                    '%.2f h night differential but worked %s-%s, never past 22:00',
                    $pay['night_diff_hours'], $in, $out,
                ));
            }
        }

        // 8. A regular holiday that pays nothing. Under the client's own rule a
        //    regular holiday not worked still pays the full daily rate when the
        //    day before was worked; the calculator pays zero.
        if ($record->holiday_type === 'regular' && (float) $pay['total'] == 0.0) {
            $this->flag($employee, $date, 'regular holiday pays 0.00 — unworked regular holidays are not being paid');
        }

        // 9. The contested company rule, surfaced rather than hidden: it is
        //    against DOLE and against the client's own written spec, and it
        //    reduces pay, so every instance is listed.
        if (! empty($pay['holiday_forfeited'])) {
            $this->flag($employee, $date, 'regular holiday premium FORFEITED by the prior-day rule (company policy, not DOLE)');
        }
    }

    private function flag(Employee $employee, string $date, string $message): void {
        $name = $employee->short_name ?: $employee->full_name;
        $this->findings[] = [$name . ' (' . $employee->employee_code . ')', $date, $message];
    }

    private function report(int $checked, int $people): int {
        if ($this->findings === []) {
            $this->info("  Nothing to flag. {$checked} day records across {$people} employees.");
            $this->newLine();

            return self::SUCCESS;
        }

        $current = null;
        foreach ($this->findings as [$who, $date, $message]) {
            if ($who !== $current) {
                $this->newLine();
                $this->line("  <comment>{$who}</comment>");
                $current = $who;
            }
            $this->line(sprintf('    %-12s %s', $date, $message));
        }

        $this->newLine();
        $this->warn(sprintf('  %d item(s) to check, from %d day records across %d employees.',
            count($this->findings), $checked, $people));
        $this->line('  These are things to confirm with whoever encoded them, not necessarily errors.');
        $this->newLine();

        return self::SUCCESS;
    }

    private function minutes(mixed $time): ?int {
        if (! $time) {
            return null;
        }
        [$h, $m] = array_pad(array_map('intval', explode(':', (string) $time)), 2, 0);

        return $h * 60 + $m;
    }

    /** Hours between two times, rolling past midnight, or null if either is absent. */
    private function spanHours(mixed $from, mixed $to): ?float {
        $start = $this->minutes($from);
        $end = $this->minutes($to);
        if ($start === null || $end === null) {
            return null;
        }
        if ($end <= $start) {
            $end += 24 * 60;
        }

        return ($end - $start) / 60.0;
    }

    private function hhmm(mixed $time): string {
        return $time === null ? '—' : substr((string) $time, 0, 5);
    }
}
