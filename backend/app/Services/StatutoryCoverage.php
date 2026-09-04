<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\PayrollSetting;

/**
 * Which statutory deductions a cutoff is missing.
 *
 * SSS, Pag-IBIG and PhilHealth are stored as adjustment rows written by the
 * Generate button rather than derived when a payslip is drawn, so a cutoff
 * nobody generated shows no deductions at all — and looks like a perfectly
 * ordinary payslip while doing it. Nothing distinguishes "this employee owes
 * nothing" from "somebody forgot to press the button". This tells the two
 * apart so the screens can say so.
 *
 * A category counts as covered if ANY row of it exists in the window, whether
 * this generator wrote it or somebody typed it: a hand-entered contribution is
 * a deliberate answer, not an omission.
 */
class StatutoryCoverage {
    public const CATEGORIES = ['sss', 'philhealth', 'pagibig'];

    public function __construct(private PeriodEarnings $earnings) {}

    /**
     * Categories with no row for this window, or [] when there is nothing to
     * deduct from — an employee who earned nothing in the period owes nothing,
     * and flagging them would train people to ignore the warning.
     *
     * @return list<string>
     */
    public function missingFor(Employee $employee, array $window, PayrollSetting $settings): array {
        if ($this->earnings->sssBasis($employee, $window, $settings) <= 0.0) {
            return [];
        }

        $present = PayrollAdjustment::where('employee_id', $employee->id)
            ->whereIn('category', self::CATEGORIES)
            ->whereDate('date', '>=', $window['from'])
            ->whereDate('date', '<=', $window['to'])
            ->pluck('category')->unique()->all();

        return array_values(array_diff(self::CATEGORIES, $present));
    }
}
