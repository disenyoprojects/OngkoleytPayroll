<?php

namespace Tests\Unit;

use App\Services\SssContributionCalculator;
use Tests\TestCase;

class SssContributionCalculatorTest extends TestCase {
    public function test_looks_up_the_correct_bracket(): void {
        $calc = new SssContributionCalculator();

        $this->assertSame(250.00, $calc->employeeShareFor(4000.00));
        $this->assertSame(250.00, $calc->employeeShareFor(5249.99));
        $this->assertSame(275.00, $calc->employeeShareFor(5250.00));
        // Client worksheet example: net 7,487.38 -> 375.00 employee share.
        $this->assertSame(375.00, $calc->employeeShareFor(7487.38));
        // Client worksheet example: monthly net 14,955.81 -> 750.00 employee share.
        $this->assertSame(750.00, $calc->employeeShareFor(14955.81));
        $this->assertSame(1250.00, $calc->employeeShareFor(25249.99));
    }

    public function test_compensation_above_the_top_bracket_falls_back_to_the_highest_share(): void {
        $calc = new SssContributionCalculator();

        $this->assertSame(1250.00, $calc->employeeShareFor(100000.00));
    }
}
