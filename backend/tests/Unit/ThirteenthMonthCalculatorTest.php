<?php

namespace Tests\Unit;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeEarning;
use App\Models\PayrollSetting;
use App\Services\ThirteenthMonthCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThirteenthMonthCalculatorTest extends TestCase {
    use RefreshDatabase;

    public function test_monthly_breakdown_sums_basic_pay_from_attendance_for_worked_months(): void {
        $settings = PayrollSetting::current(); // daily_basic_rate 505, includes only BASIC by default
        $employee = Employee::factory()->for(Branch::factory())->create([
            'hire_date' => '2026-01-01',
            'resignation_date' => null,
        ]);
        AttendanceRecord::factory()->for($employee)->create(['work_date' => '2026-01-05', 'clock_in' => '08:00:00', 'clock_out' => '17:00:00']);
        AttendanceRecord::factory()->for($employee)->create(['work_date' => '2026-01-06', 'clock_in' => '08:00:00', 'clock_out' => '17:00:00']);

        $breakdown = (new ThirteenthMonthCalculator())->monthlyBreakdown($employee, $settings, 2026);

        $january = collect($breakdown)->firstWhere('month', 1);
        $this->assertTrue($january['worked']);
        $this->assertGreaterThan(0, $january['basic_pay']);
        $february = collect($breakdown)->firstWhere('month', 2);
        $this->assertSame(0.0, $february['basic_pay']);
    }

    public function test_other_earnings_are_only_included_when_settings_include_the_code(): void {
        $settings = PayrollSetting::current();
        $settings->update(['included_earnings' => ['BASIC', 'BONUS']]);
        $employee = Employee::factory()->for(Branch::factory())->create(['hire_date' => '2026-01-01']);
        EmployeeEarning::create(['employee_id' => $employee->id, 'year' => 2026, 'month' => 6, 'code' => 'BONUS', 'amount' => 3000]);
        EmployeeEarning::create(['employee_id' => $employee->id, 'year' => 2026, 'month' => 6, 'code' => 'ALLOWANCE', 'amount' => 1500]);

        $breakdown = (new ThirteenthMonthCalculator())->monthlyBreakdown($employee, $settings, 2026);

        $june = collect($breakdown)->firstWhere('month', 6);
        $this->assertSame(3000.0, $june['month_total_included']);
    }

    public function test_eligibility_requires_minimum_months_and_included_employment_type(): void {
        $settings = PayrollSetting::current();
        $settings->update(['minimum_months' => 3, 'employment_types_included' => ['regular']]);

        $tooShort = Employee::factory()->for(Branch::factory())->create([
            'employment_type' => 'regular', 'hire_date' => '2026-11-01', 'resignation_date' => null,
        ]);
        $wrongType = Employee::factory()->for(Branch::factory())->create([
            'employment_type' => 'seasonal', 'hire_date' => '2026-01-01', 'resignation_date' => null,
        ]);
        $eligible = Employee::factory()->for(Branch::factory())->create([
            'employment_type' => 'regular', 'hire_date' => '2026-01-01', 'resignation_date' => null,
        ]);

        $calculator = new ThirteenthMonthCalculator();
        $this->assertFalse($calculator->isEligible($tooShort, $settings, 2026));
        $this->assertFalse($calculator->isEligible($wrongType, $settings, 2026));
        $this->assertTrue($calculator->isEligible($eligible, $settings, 2026));
    }

    public function test_worked_months_have_float_typed_pay_values_even_when_zero(): void {
        $settings = PayrollSetting::current();
        $employee = Employee::factory()->for(Branch::factory())->create([
            'hire_date' => '2026-01-01',
            'resignation_date' => null,
        ]);

        $breakdown = (new ThirteenthMonthCalculator())->monthlyBreakdown($employee, $settings, 2026);
        $march = collect($breakdown)->firstWhere('month', 3);

        $this->assertTrue($march['worked']);
        $this->assertIsFloat($march['basic_pay']);
        $this->assertIsFloat($march['ot_pay']);
        $this->assertIsFloat($march['other_pay']);
        $this->assertIsFloat($march['month_total_included']);
        $this->assertIsFloat((new ThirteenthMonthCalculator())->computedAmount($employee, $settings, 2026));
    }
}
