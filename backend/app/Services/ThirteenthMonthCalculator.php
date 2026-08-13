<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeEarning;
use App\Models\PayrollSetting;

class ThirteenthMonthCalculator {
    public function __construct(private AttendancePayCalculator $payCalculator = new AttendancePayCalculator()) {}

    public function monthlyBreakdown(Employee $employee, PayrollSetting $settings, int $year): array {
        $included = $settings->included_earnings;
        $dailyRate = $employee->daily_basic_rate === null ? (float) $settings->daily_basic_rate : (float) $employee->daily_basic_rate;
        $months = [];

        for ($month = 1; $month <= 12; $month++) {
            $worked = $employee->isActiveDuring($month, $year);

            $basicPay = 0.0;
            $otPay = 0.0;
            if ($worked) {
                $records = AttendanceRecord::where('employee_id', $employee->id)
                    ->whereYear('work_date', $year)
                    ->whereMonth('work_date', $month)
                    ->whereNotNull('clock_out')
                    ->get();

                foreach ($records as $record) {
                    // Days worked on a declared holiday don't count toward the
                    // 13th month base at all (not even at the plain rate).
                    if ($record->holiday_type !== null) {
                        continue;
                    }
                    // No-pay days (absent, AWOL, leave, unworked rest day, etc.)
                    // don't count as a day worked, even though the record still
                    // carries clock in/out values.
                    if (in_array($record->absence_type, AttendancePayCalculator::NO_PAY_ABSENCES, true)) {
                        continue;
                    }
                    $record->setRelation('employee', $employee);
                    $pay = $this->payCalculator->computeForRecord($record, $settings);
                    if ($pay === null) {
                        continue;
                    }
                    // 13th-month base = days worked (excluding holidays) × the
                    // daily basic rate — a flat day count, not hour-prorated.
                    if (in_array('BASIC', $included, true)) {
                        $basicPay += $dailyRate * ($record->absence_type === 'half_day' ? 0.5 : 1.0);
                    }
                    if (in_array('OVERTIME', $included, true)) {
                        $otPay += (float) $pay['base_ot'];
                    }
                }
            }

            $otherPay = $worked
                ? (float) EmployeeEarning::where('employee_id', $employee->id)
                    ->where('year', $year)->where('month', $month)
                    ->whereIn('code', $included)
                    ->sum('amount')
                : 0.0;

            $months[] = [
                'month' => $month,
                'worked' => $worked,
                'basic_pay' => (float) round($basicPay, 2),
                'ot_pay' => (float) round($otPay, 2),
                'other_pay' => (float) round($otherPay, 2),
                'month_total_included' => (float) round($basicPay + $otPay + $otherPay, 2),
            ];
        }

        return $months;
    }

    public function isEligible(Employee $employee, PayrollSetting $settings, int $year): bool {
        if (! in_array($employee->employment_type, $settings->employment_types_included, true)) {
            return false;
        }

        $monthsWorked = collect($this->monthlyBreakdown($employee, $settings, $year))
            ->where('worked', true)
            ->count();

        return $monthsWorked >= $settings->minimum_months;
    }

    public function computedAmount(Employee $employee, PayrollSetting $settings, int $year): float {
        $total = (float) collect($this->monthlyBreakdown($employee, $settings, $year))
            ->sum('month_total_included');

        return (float) round($total / 12, 2);
    }
}
