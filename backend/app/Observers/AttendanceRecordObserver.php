<?php

namespace App\Observers;

use App\Http\Controllers\Admin\StatutoryDeductionController;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\PayrollSetting;
use App\Services\PayslipPeriod;
use App\Services\PeriodEarnings;
use App\Services\SssPeriodContribution;

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
        private SssPeriodContribution $contribution,
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
        $month = $date->format('Y-m');
        $settings = PayrollSetting::current();

        $this->refreshPeriod($employee, $month, $date->day <= 15 ? 'first' : 'second', $settings);

        // The second cutoff's SSS is the month's contribution less whatever the
        // first took, so a change in the first half moves it too. Refresh it as
        // well or the two halves stop adding up to the month.
        if ($date->day <= 15) {
            $this->refreshPeriod($employee, $month, 'second', $settings);
        }
    }

    private function refreshPeriod(Employee $employee, string $month, string $period, PayrollSetting $settings): void {
        $window = PayslipPeriod::resolve($month, $period);

        $rows = PayrollAdjustment::where('employee_id', $employee->id)
            ->whereIn('category', ['sss', 'philhealth'])
            ->where('reason', StatutoryDeductionController::AUTO_REASON)
            ->whereDate('date', '>=', $window['from'])
            ->whereDate('date', '<=', $window['to'])
            ->get();

        foreach ($rows as $row) {
            // Both figures come from the same services the generator uses, so
            // an edit here and a re-run there land on the same number.
            $amount = $row->category === 'sss'
                ? -$this->contribution->forPeriod($employee, $month, $period, $settings)
                : -round($this->earnings->sum($employee, $window, $settings, 'base_wage') * StatutoryDeductionController::PHILHEALTH_RATE, 2);

            if (round((float) $row->amount, 2) !== $amount) {
                $row->update(['amount' => $amount]);
            }
        }
    }
}
