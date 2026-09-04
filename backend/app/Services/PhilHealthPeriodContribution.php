<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PayrollSetting;

/**
 * What PhilHealth to take off a given cutoff.
 *
 * The premium is 5% of the monthly basic salary, shared equally, so the
 * employee pays 2.5%. Two things make it a monthly figure rather than a
 * per-cutoff one, both from the DOLE Handbook on Workers' Statutory Monetary
 * Benefits (2024) p.57:
 *
 *   "For those whose basic monthly salary is below the income floor, the
 *    premium contribution shall be computed based on the income floor, while
 *    those whose basic monthly income is above the income ceiling, the premium
 *    contribution shall be computed based on the income ceiling."
 *
 * Floor 10,000 and ceiling 100,000, so the employee share is never less than
 * 250.00 nor more than 2,500.00 for the month. Charging 2.5% of each cutoff
 * separately misses both clamps: a month of 3,000 + 6,000 basic came to 225.00
 * where the floor puts it at 250.00.
 *
 * The month is settled the same way as SSS — the first cutoff pays 2.5% of its
 * own basic, and the second pays the balance of the month's contribution. Above
 * the floor and below the ceiling the rate is flat, so the two halves add up to
 * 2.5% of the month either way and nothing changes for most people; the split
 * only bites where a clamp applies.
 */
class PhilHealthPeriodContribution {
    /** Employee share: half of the 5% premium. */
    public const RATE = 0.025;
    public const INCOME_FLOOR = 10000.0;
    public const INCOME_CEILING = 100000.0;

    public function __construct(private PeriodEarnings $earnings) {}

    /** The contribution for this cutoff, as a positive amount. */
    public function forPeriod(Employee $employee, string $month, string $period, PayrollSetting $settings): float {
        if ($period === 'first') {
            // A half-month is not a month, so the monthly floor does not apply
            // here — it is settled in the second cutoff once the month is known.
            return round($this->basicFor($employee, $month, 'first', $settings) * self::RATE, 2);
        }

        $monthly = $this->monthlyShare($this->basicFor($employee, $month, 'whole', $settings));

        if ($period === 'whole') {
            return $monthly;
        }

        return max(0.0, round(
            $monthly - $this->earnings->deductedInFirstCutoff($employee, $month, 'philhealth'), 2
        ));
    }

    /** The month's employee share, with the income floor and ceiling applied. */
    public function monthlyShare(float $monthlyBasic): float {
        if ($monthlyBasic <= 0.0) {
            return 0.0; // nothing earned, nothing owed
        }

        $clamped = min(max($monthlyBasic, self::INCOME_FLOOR), self::INCOME_CEILING);

        return round($clamped * self::RATE, 2);
    }

    /**
     * The basic salary the premium is read from: the un-premiumed basic wage,
     * excluding overtime, night differential and holiday/rest premiums.
     */
    private function basicFor(Employee $employee, string $month, string $period, PayrollSetting $settings): float {
        return $this->earnings->sum($employee, PayslipPeriod::resolve($month, $period), $settings, 'base_wage');
    }
}
