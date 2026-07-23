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
                    $pay = $this->payCalculator->compute($record->clock_in, $record->clock_out, $settings);
                    if ($pay === null) {
                        continue;
                    }
                    if (in_array('BASIC', $included, true)) {
                        $basicPay += (float) $pay['basic'];
                    }
                    if (in_array('OVERTIME', $included, true)) {
                        $otPay += (float) $pay['ot'];
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
