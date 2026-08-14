<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Employee;
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
}
