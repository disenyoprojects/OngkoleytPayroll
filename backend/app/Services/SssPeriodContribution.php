<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PayrollSetting;

/**
 * What SSS to take off a given cutoff.
 *
 * The two halves of the month are not computed alike, and this is the client's
 * stated practice rather than anything the bracket table says:
 *
 *   First cutoff  — the bracket read from that cutoff's own net earnings. It is
 *                   a provisional amount; the month is not over yet.
 *   Second cutoff — the bracket read from the WHOLE month's net earnings, which
 *                   is the real contribution, less whatever the first cutoff
 *                   already took. The remainder is what comes off.
 *
 * Worked example supplied by the client (Ruby Rose Anudon, August 2026):
 *
 *   Aug 1-15 gross    8,322.23  -> bracket 425.00   (taken in the first cutoff)
 *   Aug 16-31 gross   7,621.12
 *   month total      15,943.35  -> bracket 800.00   (the month's contribution)
 *   less first cutoff             -425.00
 *   second cutoff                  375.00
 *
 * Reading the first cutoff's figure from the adjustment rows rather than
 * recomputing it means a hand-corrected first half is honoured: the second
 * cutoff collects the balance of the month either way.
 */
class SssPeriodContribution {
    public function __construct(
        private PeriodEarnings $earnings,
        private SssContributionCalculator $brackets,
    ) {}

    /** The contribution for this cutoff, as a positive amount. */
    public function forPeriod(Employee $employee, string $month, string $period, PayrollSetting $settings): float {
        if ($period !== 'second') {
            // First cutoff, or a whole-month run: the bracket on the window's
            // own earnings, with nothing to net off.
            $window = PayslipPeriod::resolve($month, $period);

            return round($this->brackets->employeeShareFor(
                $this->earnings->sssBasis($employee, $window, $settings)
            ), 2);
        }

        $monthBasis = $this->earnings->sssBasis($employee, PayslipPeriod::resolve($month, 'whole'), $settings);
        $monthly = $this->brackets->employeeShareFor($monthBasis);

        // Never hand money back: if the first cutoff already took more than the
        // month turned out to be worth, the second simply takes nothing.
        return max(0.0, round($monthly - $this->earnings->deductedInFirstCutoff($employee, $month, 'sss'), 2));
    }
}
