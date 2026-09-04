<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\PayrollSetting;

class PeriodEarnings {
    public function __construct(private AttendancePayCalculator $calculator = new AttendancePayCalculator()) {}

    /**
     * Sum of a per-record pay figure across a date window. 'total' is the
     * period's net earnings (gross less tardiness) — the SSS basis; 'base_wage'
     * is the un-premiumed basic — the PhilHealth basis.
     */
    public function sum(Employee $employee, array $window, PayrollSetting $settings, string $payKey): float {
        return (float) AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('work_date', '>=', $window['from'])
            ->whereDate('work_date', '<=', $window['to'])
            ->whereNotNull('clock_out')
            ->get()
            ->sum(function (AttendanceRecord $record) use ($settings, $employee, $payKey) {
                $record->setRelation('employee', $employee);
                $pay = $this->calculator->computeForRecord($record, $settings);

                return (float) ($pay[$payKey] ?? 0.0);
            });
    }

    /**
     * The compensation the SSS bracket is read from: the period's net earnings
     * (gross less tardiness and undertime) plus allowances, bonuses and
     * anything else added to the pay.
     *
     * This lives here because two callers need it and used to compute it
     * differently — the generator added the adjustments and the attendance
     * observer did not, so every edit to a clock time quietly re-derived the
     * contribution from wages alone and dropped the employee a bracket or
     * more. One definition, both callers.
     */
    public function sssBasis(Employee $employee, array $window, PayrollSetting $settings): float {
        return $this->sum($employee, $window, $settings, 'total')
            + $this->positiveAdjustments($employee, $window);
    }

    /**
     * What a statutory category already took in the first half of the month, as
     * a positive amount. Both SSS and PhilHealth settle on the month and net
     * off the first cutoff, and both read that figure from the adjustment rows
     * rather than recomputing it — so a first half corrected by hand is
     * honoured and the month still collects its proper total.
     */
    public function deductedInFirstCutoff(Employee $employee, string $month, string $category): float {
        $first = PayslipPeriod::resolve($month, 'first');

        return round(abs((float) PayrollAdjustment::where('employee_id', $employee->id)
            ->where('category', $category)
            ->whereDate('date', '>=', $first['from'])
            ->whereDate('date', '<=', $first['to'])
            ->sum('amount')), 2);
    }

    /**
     * Allowances, bonuses and the like within the window. Only positive rows
     * count, so the statutory deductions themselves cannot feed back into the
     * bracket they were looked up from.
     */
    public function positiveAdjustments(Employee $employee, array $window): float {
        return round((float) PayrollAdjustment::where('employee_id', $employee->id)
            ->whereDate('date', '>=', $window['from'])
            ->whereDate('date', '<=', $window['to'])
            ->where('amount', '>', 0)->sum('amount'), 2);
    }
}
