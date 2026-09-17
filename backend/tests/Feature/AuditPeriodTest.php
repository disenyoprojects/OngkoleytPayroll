<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Each case here is a fault that reached a real payslip. The audit exists to
 * catch the next one before the client does, so the tests reproduce the
 * originals rather than invent plausible-looking data.
 */
class AuditPeriodTest extends TestCase {
    use RefreshDatabase;

    private function employee(string $code = 'EMP-0016'): Employee {
        return Employee::factory()->for(Branch::factory())->create([
            'employee_code' => $code, 'full_name' => 'Christopher T. Navarro',
            'short_name' => 'Christopher', 'daily_basic_rate' => 505,
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

    private function audit(string $args = '') {
        return $this->artisan("payroll:audit-period --month=2026-09 --period=first {$args}");
    }

    /** Christopher's 2026-09-08: OT pair 20:06-07:47, worth PHP 1,049.98. */
    public function test_it_flags_an_overtime_pair_longer_than_a_shift(): void {
        $employee = $this->employee();
        $this->day($employee, '2026-09-08', [
            'shift_start' => '10:00:00', 'shift_end' => '19:00:00',
            'clock_in' => '09:53:00', 'clock_out' => '19:59:00',
            'ot_in' => '20:06:00', 'ot_out' => '07:47:00',
        ]);

        // One fragment only: chained output assertions consume the buffer as
        // they match, so a second one on the same line does not survive it.
        $this->audit()->expectsOutputToContain('20:06-07:47')->assertSuccessful();
    }

    /** Christopher's 2026-09-10: clocked 08:00-17:00, paid PHP 0.00. */
    public function test_it_flags_a_worked_day_that_pays_nothing(): void {
        $employee = $this->employee();
        $this->day($employee, '2026-09-10', ['absence_type' => 'absent']);

        $this->audit()->expectsOutputToContain('pays 0.00')->assertSuccessful();
    }

    /** Ruby's 2026-08-17: a 12h35m break inside a 9h34m day, PHP 731.20 charged. */
    public function test_it_flags_a_break_longer_than_the_day_it_sits_in(): void {
        $employee = $this->employee();
        $this->day($employee, '2026-09-03', [
            'clock_in' => '11:00:00', 'clock_out' => '20:34:00',
            'break_out' => '12:00:00', 'break_in' => '00:35:00',
        ]);

        $this->audit()->expectsOutputToContain('longer than the')->assertSuccessful();
    }

    /** Ruby's 2026-09-12: clock-out keyed 08:21 where 20:21 was meant. */
    public function test_it_flags_an_implausible_clock_span(): void {
        $employee = $this->employee();
        $this->day($employee, '2026-09-12', ['clock_in' => '09:00:00', 'clock_out' => '08:21:00']);

        $this->audit()->expectsOutputToContain('clock span')->assertSuccessful();
    }

    /** A clock-in with no clock-out drops off the payslip without saying so. */
    public function test_it_flags_a_day_never_clocked_out(): void {
        $employee = $this->employee();
        $this->day($employee, '2026-09-04', ['clock_out' => null]);

        $this->audit()->expectsOutputToContain('never clocked out')->assertSuccessful();
    }

    /** The client's own rule pays an unworked regular holiday; the system does not. */
    public function test_it_flags_an_unpaid_regular_holiday(): void {
        $employee = $this->employee();
        $this->day($employee, '2026-09-05', [
            'clock_in' => null, 'clock_out' => null, 'holiday_type' => 'regular',
        ]);

        $this->audit()->expectsOutputToContain('regular holiday')->assertSuccessful();
    }

    /** A clean cutoff says so plainly, or the audit would be noise. */
    public function test_a_clean_period_reports_nothing(): void {
        $employee = $this->employee();
        $this->day($employee, '2026-09-01');
        $this->day($employee, '2026-09-02');

        $this->audit()
            ->expectsOutputToContain('Nothing to flag')
            ->assertSuccessful();
    }

    /** Basic must be days x rate exactly now the daily rate is fixed. */
    public function test_a_ten_hour_shift_does_not_break_the_days_times_rate_check(): void {
        $employee = $this->employee();
        $this->day($employee, '2026-09-01', [
            'shift_start' => '09:00:00', 'shift_end' => '19:00:00', 'clock_out' => '19:00:00',
        ]);

        $this->audit()->expectsOutputToContain('Nothing to flag')->assertSuccessful();
    }

    public function test_it_can_be_limited_to_one_employee(): void {
        $one = $this->employee('EMP-0016');
        $two = $this->employee('EMP-0020');
        $this->day($one, '2026-09-01');
        $this->day($two, '2026-09-01', ['absence_type' => 'absent']);

        $this->audit('--employee=EMP-0016')
            ->expectsOutputToContain('Nothing to flag')
            ->assertSuccessful();
    }
}
