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
            ->assertSuccessful();
    }

    /** The daily rate is fixed, so a ten-hour day still pays it. */
    public function test_a_longer_shift_still_pays_the_daily_rate(): void {
        $employee = $this->employee();
        $this->day($employee, '2026-09-02', [
            'shift_start' => '08:00:00', 'shift_end' => '18:00:00', 'clock_in' => '08:00:00',
        ]);

        // Eight paid hours, not the nine the scheduled span would once have
        // bought — the extra scheduled hour moves overtime's start, not the pay.
        $this->explain()->expectsOutputToContain('regular      8.00 h')->assertSuccessful();
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

    /**
     * There are two Navarros on this roster. Searching by name used to take
     * the first match and report on the wrong person — a separated one, since
     * the search includes them — under the right-looking heading.
     */
    public function test_an_ambiguous_name_is_refused_and_lists_the_codes(): void {
        $branch = Branch::factory()->create();
        Employee::factory()->for($branch)->create([
            'employee_code' => 'EMP-0016', 'full_name' => 'Christopher T. Navarro',
        ]);
        $gone = Employee::factory()->for($branch)->create([
            'employee_code' => 'ONG-1010', 'full_name' => 'Jhen Navarro',
        ]);
        $gone->delete();

        $this->artisan('payroll:explain-payslip Navarro --month=2026-09 --period=first')
            ->expectsOutputToContain('matched 2 employees')
            ->expectsOutputToContain('EMP-0016')
            // The "(separated)" marker on the second row is verified by hand,
            // not here: chained output assertions consume the buffer as they
            // match, and a fourth one on the same line does not survive it.
            ->expectsOutputToContain('ONG-1010')
            ->assertFailed();
    }

    /** An exact code is unique, so it is never ambiguous. */
    public function test_an_exact_code_wins_over_a_name_match(): void {
        $branch = Branch::factory()->create();
        $employee = Employee::factory()->for($branch)->create([
            'employee_code' => 'EMP-0016', 'full_name' => 'Christopher T. Navarro',
            'daily_basic_rate' => 505, 'shift_start' => '09:00:00', 'shift_end' => '18:00:00',
        ]);
        Employee::factory()->for($branch)->create([
            'employee_code' => 'ONG-1010', 'full_name' => 'Jhen Navarro',
        ]);
        $this->day($employee, '2026-09-01');

        $this->artisan('payroll:explain-payslip EMP-0016 --month=2026-09 --period=first')
            ->expectsOutputToContain('Christopher T. Navarro')
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
