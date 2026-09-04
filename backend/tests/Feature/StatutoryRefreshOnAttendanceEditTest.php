<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\PayrollSetting;
use App\Models\User;
use App\Services\PayslipPeriod;
use App\Services\PeriodEarnings;
use App\Services\SssContributionCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatutoryRefreshOnAttendanceEditTest extends TestCase {
    use RefreshDatabase;

    private function employeeWithDays(int $days): Employee {
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => 600]);
        foreach (range(1, $days) as $day) {
            AttendanceRecord::factory()->for($employee)->create([
                'work_date' => sprintf('2026-07-%02d', $day),
                'shift_start' => '08:00:00', 'shift_end' => '17:00:00',
                'clock_in' => '08:00:00', 'clock_out' => '17:00:00', 'status' => 'approved',
            ]);
        }

        return $employee;
    }

    public function test_editing_attendance_refreshes_the_periods_sss(): void {
        $admin = User::factory()->create();
        $employee = $this->employeeWithDays(13);

        $this->actingAs($admin)->postJson('/api/admin/payroll/period/statutory?month=2026-07&period=first')->assertOk();
        // 13 days x 600 = 7,800 -> the 7,750-8,249.99 bracket -> 400.
        $sss = PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'sss')->firstOrFail();
        $this->assertSame('-400.00', $sss->amount);

        // Correcting a day down to a half shift drops the period's net earnings
        // into a lower bracket; the stored contribution should follow.
        AttendanceRecord::where('employee_id', $employee->id)->whereDate('work_date', '2026-07-13')->firstOrFail()
            ->update(['clock_out' => '12:00:00']);

        $this->assertNotSame('-400.00', $sss->fresh()->amount);
    }

    public function test_deleting_a_day_refreshes_the_periods_sss(): void {
        $admin = User::factory()->create();
        $employee = $this->employeeWithDays(13);
        $this->actingAs($admin)->postJson('/api/admin/payroll/period/statutory?month=2026-07&period=first')->assertOk();
        $sss = PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'sss')->firstOrFail();

        AttendanceRecord::where('employee_id', $employee->id)->whereDate('work_date', '2026-07-13')->firstOrFail()->delete();

        // 12 days x 600 = 7,200 -> the 6,750-7,249.99 bracket -> 350.
        $this->assertSame('-350.00', $sss->fresh()->amount);
    }

    public function test_a_hand_entered_row_is_not_refreshed(): void {
        $employee = $this->employeeWithDays(13);
        $manual = PayrollAdjustment::create([
            'employee_id' => $employee->id, 'date' => '2026-07-15', 'label' => 'SSS',
            'category' => 'sss', 'amount' => -180.00, 'paid' => false,
            'reason' => 'Agreed with the employee', 'created_by' => User::factory()->create()->id,
        ]);

        AttendanceRecord::where('employee_id', $employee->id)->whereDate('work_date', '2026-07-13')->firstOrFail()
            ->update(['clock_out' => '12:00:00']);

        $this->assertSame('-180.00', $manual->fresh()->amount);
    }

    public function test_no_row_is_created_for_a_period_never_generated(): void {
        $employee = $this->employeeWithDays(13);

        AttendanceRecord::where('employee_id', $employee->id)->whereDate('work_date', '2026-07-13')->firstOrFail()
            ->update(['clock_out' => '12:00:00']);

        $this->assertSame(0, PayrollAdjustment::where('employee_id', $employee->id)->count());
    }

    /**
     * The second cutoff is the month's bracket less whatever the first took
     * (see SssPeriodContribution), so correcting a day in the first half has
     * to move the second half too. This test used to assert the opposite —
     * that each cutoff stood alone — which is the behaviour the client
     * corrected us on: the month's total contribution is what must come out,
     * however the two halves fall.
     */
    public function test_correcting_the_first_cutoff_rebalances_the_second(): void {
        $admin = User::factory()->create();
        $employee = $this->employeeWithDays(13);
        foreach (range(16, 28) as $day) {
            AttendanceRecord::factory()->for($employee)->create([
                'work_date' => sprintf('2026-07-%02d', $day),
                'shift_start' => '08:00:00', 'shift_end' => '17:00:00',
                'clock_in' => '08:00:00', 'clock_out' => '17:00:00', 'status' => 'approved',
            ]);
        }
        $this->actingAs($admin)->postJson('/api/admin/payroll/period/statutory?month=2026-07&period=first')->assertOk();
        $this->actingAs($admin)->postJson('/api/admin/payroll/period/statutory?month=2026-07&period=second')->assertOk();

        // 13 days x 600 each half. First: bracket(7,800) = 400. Month: bracket(15,600) = 775,
        // less the 400 already taken = 375 for the second.
        $sss = fn (string $date) => round((float) PayrollAdjustment::where('employee_id', $employee->id)
            ->where('category', 'sss')->whereDate('date', $date)->firstOrFail()->amount, 2);
        $this->assertSame(-400.00, $sss('2026-07-15'));
        $this->assertSame(-375.00, $sss('2026-07-31'));

        AttendanceRecord::where('employee_id', $employee->id)->whereDate('work_date', '2026-07-13')->firstOrFail()
            ->update(['clock_out' => '12:00:00']);

        // Shortening that day lowers both the first half and the month, so the
        // first drops a bracket and the month's own bracket moves with it. What
        // must hold either way is that the two halves add up to the month.
        $this->assertNotSame(-400.00, $sss('2026-07-15'));

        $monthly = app(SssContributionCalculator::class)->employeeShareFor(
            app(PeriodEarnings::class)->sssBasis(
                $employee->fresh(), PayslipPeriod::resolve('2026-07', 'whole'), PayrollSetting::current(),
            )
        );
        $this->assertSame(-$monthly, $sss('2026-07-15') + $sss('2026-07-31'));
    }
}
