<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The day-by-day derivation behind a cutoff's earnings.
 *
 * Written after three payslips in a week had to be reverse-engineered from
 * their totals: two where a single day scheduled ten hours made the period
 * beat days x rate by exactly one hour's pay, and one where overtime was
 * queried and the deciding field — the second clock pair — appears on no
 * screen at all.
 */
class ExplainPayslipTest extends TestCase {
    use RefreshDatabase;

    private function employee(): Employee {
        return Employee::factory()->for(Branch::factory())->create([
            'employee_code' => 'EMP-0016', 'full_name' => 'Ronald M. Catigum',
            'short_name' => 'Ronald', 'daily_basic_rate' => 505,
            'shift_start' => '09:00:00', 'shift_end' => '18:00:00',
        ]);
    }

    private function day(Employee $employee, string $date, array $extra = []): void {
        AttendanceRecord::create(array_merge([
            'employee_id' => $employee->id, 'work_date' => $date,
            'shift_start' => '09:00:00', 'shift_end' => '18:00:00',
            'clock_in' => '09:00:00', 'clock_out' => '18:00:00', 'status' => 'approved',
        ], $extra));
    }

    private function explain(string $args = '') {
        return $this->artisan("payroll:explain-payslip EMP-0016 --month=2026-09 --period=first {$args}");
    }

    /** The case that prompted it: one day scheduled ten hours, not nine. */
    public function test_it_flags_a_day_scheduled_longer_than_the_rest(): void {
        $employee = $this->employee();
        $this->day($employee, '2026-09-01');
        $this->day($employee, '2026-09-02', [
            'shift_start' => '08:00:00', 'shift_end' => '18:00:00', 'clock_in' => '08:00:00',
        ]);

        // Short fragments: the test buffer wraps long lines, so an assertion
        // spanning most of one can straddle the break and never match.
        $this->explain()
            ->expectsOutputToContain('Not the usual 9h shift')
            ->expectsOutputToContain('10.00h sched')
            ->expectsOutputToContain('9.00 paid hours = 568.13')
            ->assertSuccessful();
    }

    /** A period of ordinary days has nothing to flag. */
    public function test_a_clean_period_flags_nothing(): void {
        $employee = $this->employee();
        $this->day($employee, '2026-09-01');
        $this->day($employee, '2026-09-02');

        $this->explain()
            ->doesntExpectOutputToContain('Days NOT scheduled')
            ->assertSuccessful();
    }

    /** The second clock pair decides overtime and appears on no screen. */
    public function test_it_shows_the_overtime_pair(): void {
        $employee = $this->employee();
        $this->day($employee, '2026-09-01', ['ot_in' => '21:18:00', 'ot_out' => '23:11:00']);

        $this->explain()
            ->expectsOutputToContain('21:18-23:11')
            ->assertSuccessful();
    }

    public function test_it_totals_the_hours_and_the_money(): void {
        $employee = $this->employee();
        $this->day($employee, '2026-09-01');
        $this->day($employee, '2026-09-02', ['clock_out' => '20:00:00']); // 2h overtime

        $this->explain()
            ->expectsOutputToContain('regular     16.00 h')
            ->expectsOutputToContain('overtime     2.00 h')
            ->assertSuccessful();
    }

    public function test_an_unknown_employee_fails_clearly(): void {
        $this->artisan('payroll:explain-payslip Nobody --month=2026-09')
            ->expectsOutputToContain('No employee matched "Nobody".')
            ->assertFailed();
    }

    public function test_an_empty_window_says_so(): void {
        $this->employee();

        $this->explain()->expectsOutputToContain('No attendance in this window.')->assertSuccessful();
    }
}
