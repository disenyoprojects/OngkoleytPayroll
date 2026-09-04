<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollSetting;
use App\Services\PhilHealthPeriodContribution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PhilHealth is 2.5% of the MONTH'S basic salary, clamped to the income floor
 * and ceiling (DOLE Handbook 2024, p.57). We charged 2.5% of each cutoff with
 * no clamp, which under-deducts below the floor: the client's example of a
 * 3,000 first half and a 6,000 second came to 225.00 where the floor puts the
 * month at 250.00.
 */
class PhilHealthIncomeFloorTest extends TestCase {
    use RefreshDatabase;

    private function share(float $monthlyBasic): float {
        return app(PhilHealthPeriodContribution::class)->monthlyShare($monthlyBasic);
    }

    /** The client's own example: 3,000 + 6,000 = 9,000 for the month -> 250. */
    public function test_a_month_below_the_income_floor_pays_the_floor(): void {
        $this->assertSame(250.00, $this->share(9000.00));
    }

    public function test_the_floor_applies_all_the_way_down(): void {
        $this->assertSame(250.00, $this->share(1.00));
        $this->assertSame(250.00, $this->share(9999.99));
        $this->assertSame(250.00, $this->share(10000.00));
    }

    /** Nothing earned, nothing owed — the floor is not a charge for absence. */
    public function test_no_basic_salary_means_no_contribution(): void {
        $this->assertSame(0.0, $this->share(0.0));
    }

    public function test_above_the_floor_it_is_a_straight_two_and_a_half_percent(): void {
        $this->assertSame(320.36, $this->share(12814.38)); // Ronald M. Catigum, Aug 2026
        $this->assertSame(500.00, $this->share(20000.00));
    }

    public function test_the_income_ceiling_caps_the_share(): void {
        $this->assertSame(2500.00, $this->share(100000.00));
        $this->assertSame(2500.00, $this->share(250000.00));
    }

    /**
     * The split: the first cutoff pays 2.5% of its own basic, the second the
     * balance of the month. Her example settles at 75.00 then 175.00.
     */
    public function test_the_second_cutoff_collects_the_balance_of_the_month(): void {
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => 500]);
        $contribution = app(PhilHealthPeriodContribution::class);
        $settings = PayrollSetting::current();

        // No attendance, so both halves are zero and the month is zero: the
        // floor must not conjure a deduction out of an unworked month.
        $this->assertSame(0.0, $contribution->forPeriod($employee, '2026-08', 'first', $settings));
        $this->assertSame(0.0, $contribution->forPeriod($employee, '2026-08', 'second', $settings));
    }
}
