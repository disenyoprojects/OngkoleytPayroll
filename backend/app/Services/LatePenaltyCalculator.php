<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollSetting;

/**
 * The late penalty for a cutoff: a flat charge per late day, so one late is
 * 75.00, two are 150.00, three 225.00, and so on.
 *
 * This generator existed once and was removed (3fb2e53) because it double-
 * charged: it wrote its figure on top of a lump somebody had already typed for
 * the same days, and its count of late days disagreed with the office's — on
 * Ian Apelado's period the shift start had been edited to match the arrival on
 * four mornings, so it found two lates where the office had counted seven.
 *
 * Both faults are designed out here rather than hoped away:
 *
 *   - Nothing is ever added to an existing charge. The generator owns one row
 *     per employee per cutoff and rewrites that row's amount; a row somebody
 *     typed is left untouched, exactly as the statutory generator behaves.
 *   - The count travels in the label ("Penalty Late (3 days)"), so a
 *     disagreement with the office's own count is visible on the adjustments
 *     list instead of hiding inside a peso figure.
 *
 * The second fault is really a data-quality one — a shift start edited to match
 * an arrival stops that morning being late — and no arithmetic here can settle
 * it. Making the count legible is what lets somebody notice.
 */
class LatePenaltyCalculator {
    public function __construct(private AttendancePayCalculator $calculator = new AttendancePayCalculator()) {}

    /** Days in the window the employee clocked in after their shift started. */
    public function lateDaysFor(Employee $employee, array $window, PayrollSetting $settings): int {
        return AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('work_date', '>=', $window['from'])
            ->whereDate('work_date', '<=', $window['to'])
            ->whereNotNull('clock_out')
            ->get()
            ->filter(function (AttendanceRecord $record) use ($settings, $employee) {
                $record->setRelation('employee', $employee);
                $pay = $this->calculator->computeForRecord($record, $settings);

                // A no-pay absence is not a late day, however the clock reads.
                return (bool) ($pay['late'] ?? false);
            })
            ->count();
    }

    /** The charge for those days, as a positive amount. */
    public function amountFor(Employee $employee, array $window, PayrollSetting $settings): float {
        return round(
            $this->lateDaysFor($employee, $window, $settings) * (float) ($settings->late_penalty_amount ?? 0),
            2,
        );
    }
}
