<?php

namespace App\Services;

class SssContributionCalculator {
    /**
     * SSS Schedule of Contributions for Business Employers and Employees,
     * effective January 2025. Each row is [max compensation in the bracket,
     * employee share]. Sourced from the printed schedule; only goes up to
     * ₱25,249.99 (the highest bracket on the page provided) — compensation
     * above that falls back to the top bracket's share. Update this table
     * if SSS publishes a revised schedule or brackets beyond ₱25,249.99.
     */
    private const BRACKETS = [
        [5249.99, 250.00],
        [5749.99, 275.00],
        [6249.99, 300.00],
        [6749.99, 325.00],
        [7249.99, 350.00],
        [7749.99, 375.00],
        [8249.99, 400.00],
        [8749.99, 425.00],
        [9249.99, 450.00],
        [9749.99, 475.00],
        [10249.99, 500.00],
        [10749.99, 525.00],
        [11249.99, 550.00],
        [11749.99, 575.00],
        [12249.99, 600.00],
        [12749.99, 625.00],
        [13249.99, 650.00],
        [13749.99, 675.00],
        [14249.99, 700.00],
        [14749.99, 725.00],
        [15249.99, 750.00],
        [15749.99, 775.00],
        [16249.99, 800.00],
        [16749.99, 825.00],
        [17249.99, 850.00],
        [17749.99, 875.00],
        [18249.99, 900.00],
        [18749.99, 925.00],
        [19249.99, 950.00],
        [19749.99, 975.00],
        [20249.99, 1000.00],
        [20749.99, 1025.00],
        [21249.99, 1050.00],
        [21749.99, 1075.00],
        [22249.99, 1100.00],
        [22749.99, 1125.00],
        [23249.99, 1150.00],
        [23749.99, 1175.00],
        [24249.99, 1200.00],
        [24749.99, 1225.00],
        [25249.99, 1250.00],
    ];

    public function employeeShareFor(float $monthlyCompensation): float {
        foreach (self::BRACKETS as [$max, $share]) {
            if ($monthlyCompensation <= $max) {
                return $share;
            }
        }

        return self::BRACKETS[array_key_last(self::BRACKETS)][1];
    }
}
