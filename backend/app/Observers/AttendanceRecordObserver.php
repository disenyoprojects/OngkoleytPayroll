<?php

namespace App\Observers;

use App\Http\Controllers\Admin\StatutoryDeductionController;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\PayrollSetting;
use App\Services\PayslipPeriod;
use App\Services\PeriodEarnings;
use App\Services\SssContributionCalculator;

/**
 * Keeps the auto-generated statutory deductions in step with attendance.
 *
 * SSS and PhilHealth are stored as adjustment rows rather than recomputed when
 * a payslip is drawn, so correcting a clock-in used to leave yesterday's
 * contribution behind at the old amount until somebody pressed Generate
 * Statutory again. Editing attendance now refreshes the affected cutoff's rows
 * in place. Rows entered by hand are never touched, and none are created here —
 * a period nobody has generated yet stays empty until it is generated.
 */
class AttendanceRecordObserver {
    public function __construct(
        private PeriodEarnings $earnings,
        private SssContributionCalculator $sss,
    ) {}

    public function saved(AttendanceRecord $record): void {
        $this->refresh($record);
    }

    public function deleted(AttendanceRecord $record): void {
        $this->refresh($record);
    }

    private function refresh(AttendanceRecord $record): void {
        $employee = Employee::withTrashed()->find($record->employee_id);
        if (! $employee) {
            return;
        }

        $date = $record->work_date;
        $window = PayslipPeriod::resolve($date->format('Y-m'), $date->day <= 15 ? 'first' : 'second');
        $settings = PayrollSetting::current();

        $rows = PayrollAdjustment::where('employee_id', $employee->id)
            ->whereIn('category', ['sss', 'philhealth'])
            ->where('reason', StatutoryDeductionController::AUTO_REASON)
            ->whereDate('date', '>=', $window['from'])
            ->whereDate('date', '<=', $window['to'])
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        foreach ($rows as $row) {
            $amount = $row->category === 'sss'
                ? -round($this->sss->employeeShareFor($this->earnings->sum($employee, $window, $settings, 'total')), 2)
                : -round($this->earnings->sum($employee, $window, $settings, 'base_wage') * StatutoryDeductionController::PHILHEALTH_RATE, 2);

            if (round((float) $row->amount, 2) !== $amount) {
                $row->update(['amount' => $amount]);
            }
        }
    }
}
