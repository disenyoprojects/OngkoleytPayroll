<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\PayslipController;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The rules that only exist across a whole month, checked by hand.
 *
 * The client's own statement of them: the first cutoff pays SSS on its own net
 * earnings; the second pays the balance of the month's bracket. PhilHealth is
 * 2.5% of the month's basic, never below the 10,000 income floor. Both settle
 * on the month, so neither can be verified from one cutoff alone — and the
 * arithmetic below is the client's, not the code's.
 */
class TwoCutoffReconciliationTest extends TestCase {
    use RefreshDatabase;

    private const HOURLY = 63.125; // 505.00 / 8

    private function employee(): Employee {
        return Employee::factory()->for(Branch::factory())->create([
            'daily_basic_rate' => 505, 'shift_start' => '09:00:00', 'shift_end' => '18:00:00',
        ]);
    }

    /** Plain 8-hour days, worked exactly to shift, so basic is days x 505.00. */
    private function workDays(Employee $employee, array $days): void {
        foreach ($days as $day) {
            AttendanceRecord::create([
                'employee_id' => $employee->id, 'work_date' => "2026-08-{$day}",
                'shift_start' => '09:00:00', 'shift_end' => '18:00:00',
                'clock_in' => '09:00:00', 'clock_out' => '18:00:00', 'status' => 'approved',
            ]);
        }
    }

    private function generate(User $admin, string $period): void {
        $this->actingAs($admin)
            ->postJson("/api/admin/payroll/period/statutory?month=2026-08&period={$period}")->assertOk();
    }

    private function totals(Employee $employee, string $period): array {
        return app(PayslipController::class)->buildPayslip($employee, '2026-08', $period)['totals'];
    }

    /**
     * Ten days each half: 5,050.00 a cutoff, 10,100.00 for the month.
     *
     *   SSS  first  bracket(5,050.00)  = 250.00      (the 5,249.99 row)
     *        month  bracket(10,100.00) = 500.00      (the 10,249.99 row)
     *        second 500.00 - 250.00    = 250.00
     *
     *   PhilHealth  first  2.5% x 5,050.00  = 126.25
     *               month  2.5% x 10,100.00 = 252.50 (above the floor)
     *               second 252.50 - 126.25  = 126.25
     */
    public function test_the_month_settles_in_the_second_cutoff(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();
        $this->workDays($employee, ['03', '04', '05', '06', '07', '10', '11', '12', '13', '14']);
        $this->workDays($employee, ['17', '18', '19', '20', '21', '24', '25', '26', '27', '28']);

        $this->generate($admin, 'first');
        $this->generate($admin, 'second');

        $first = $this->totals($employee, 'first');
        $second = $this->totals($employee, 'second');

        // Each half earned the same, which is what makes the split legible.
        $this->assertEqualsWithDelta(5050.00, $first['base_wage'], 0.005);
        $this->assertEqualsWithDelta(5050.00, $second['base_wage'], 0.005);

        $this->assertEqualsWithDelta(250.00, $first['sss'], 0.005, 'bracket on the first half alone');
        $this->assertEqualsWithDelta(250.00, $second['sss'], 0.005, 'the balance of the month');
        $this->assertEqualsWithDelta(500.00, $first['sss'] + $second['sss'], 0.005, "the month's own bracket");

        $this->assertEqualsWithDelta(126.25, $first['philhealth'], 0.005, '2.5% of the first half');
        $this->assertEqualsWithDelta(126.25, $second['philhealth'], 0.005, 'the balance of the month');
        $this->assertEqualsWithDelta(252.50, $first['philhealth'] + $second['philhealth'], 0.005);

        // Pag-IBIG is flat and owes nothing to the month.
        $this->assertEqualsWithDelta(100.00, $first['pagibig'], 0.005);
        $this->assertEqualsWithDelta(100.00, $second['pagibig'], 0.005);
    }

    /**
     * A month under the PhilHealth floor. Five days each half: 2,525.00 a
     * cutoff, 5,050.00 for the month, well under 10,000.
     *
     *   first  2.5% x 2,525.00 = 63.13            (a half month is not a month,
     *                                              so the floor does not apply)
     *   month  2.5% x 10,000.00 = 250.00          (the floor)
     *   second 250.00 - 63.13   = 186.87
     *   ------------------------------------------------------------------
     *   the month collects exactly the 250.00 the client specified
     */
    public function test_the_philhealth_floor_is_collected_across_the_two_cutoffs(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();
        $this->workDays($employee, ['03', '04', '05', '06', '07']);
        $this->workDays($employee, ['17', '18', '19', '20', '21']);

        $this->generate($admin, 'first');
        $this->generate($admin, 'second');

        $first = $this->totals($employee, 'first');
        $second = $this->totals($employee, 'second');

        $this->assertEqualsWithDelta(2525.00, $first['base_wage'], 0.005);
        $this->assertEqualsWithDelta(63.13, $first['philhealth'], 0.005);
        $this->assertEqualsWithDelta(186.87, $second['philhealth'], 0.005);
        $this->assertEqualsWithDelta(250.00, $first['philhealth'] + $second['philhealth'], 0.005, 'the floor');
    }

    /**
     * Correcting the first half after both cutoffs are generated. The month's
     * contribution is what must come out, so the second half absorbs the
     * change — this is the behaviour that made an old test wrong.
     */
    public function test_correcting_the_first_half_leaves_the_month_whole(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();
        $this->workDays($employee, ['03', '04', '05', '06', '07', '10', '11', '12', '13', '14']);
        $this->workDays($employee, ['17', '18', '19', '20', '21', '24', '25', '26', '27', '28']);

        $this->generate($admin, 'first');
        $this->generate($admin, 'second');
        $before = $this->totals($employee, 'first')['sss'] + $this->totals($employee, 'second')['sss'];
        $this->assertEqualsWithDelta(500.00, $before, 0.005);

        // A day is corrected down to a half shift, so the month earns less.
        AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('work_date', '2026-08-03')->firstOrFail()->update(['clock_out' => '13:00:00']);

        $first = $this->totals($employee, 'first');
        $second = $this->totals($employee, 'second');

        // Whatever the halves now are, together they must still be the bracket
        // the month's own earnings call for, and neither may go negative.
        $monthly = app(\App\Services\SssContributionCalculator::class)->employeeShareFor(
            app(\App\Services\PeriodEarnings::class)->sssBasis(
                $employee->fresh(),
                \App\Services\PayslipPeriod::resolve('2026-08', 'whole'),
                \App\Models\PayrollSetting::current(),
            )
        );

        $this->assertGreaterThanOrEqual(0.0, $second['sss']);
        $this->assertEqualsWithDelta($monthly, $first['sss'] + $second['sss'], 0.005,
            'the two halves must still add up to the month');
    }
}
