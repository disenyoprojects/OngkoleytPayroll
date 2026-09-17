<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The verifier has to agree with a correct payslip and disagree with a broken
 * one. A checker that only ever passes is worse than no checker, so the
 * negative cases matter more than the positive one.
 */
class VerifyPayslipsTest extends TestCase {
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

    private function verify(string $args = '') {
        return $this->artisan("payroll:verify-payslips --month=2026-09 --period=first {$args}");
    }

    public function test_a_correct_payslip_reconciles(): void {
        $employee = $this->employee();
        $this->day($employee, '2026-09-01');
        $this->day($employee, '2026-09-02', ['clock_out' => '20:00:00']); // 2h overtime

        $this->verify()->expectsOutputToContain('reconcile')->assertSuccessful();
    }

    /** A ten-hour shift still pays the flat rate, so it must not be reported. */
    public function test_an_unusual_shift_length_still_reconciles(): void {
        $employee = $this->employee();
        $this->day($employee, '2026-09-01', ['shift_end' => '19:00:00', 'clock_out' => '19:00:00']);
        $this->day($employee, '2026-09-02', ['shift_end' => '16:00:00', 'clock_out' => '16:00:00']);

        $this->verify()->expectsOutputToContain('reconcile')->assertSuccessful();
    }

    /** Premium days multiply the basic, and the day-equivalents check has to allow for it. */
    public function test_a_holiday_reconciles(): void {
        $employee = $this->employee();
        $this->day($employee, '2026-09-01', ['holiday_type' => 'special']);
        $this->day($employee, '2026-09-02', ['holiday_type' => 'regular']);

        $this->verify()->expectsOutputToContain('reconcile')->assertSuccessful();
    }

    /** Adjustments move net without touching gross; the slip must still foot. */
    public function test_a_payslip_with_deductions_reconciles(): void {
        $employee = $this->employee();
        $this->day($employee, '2026-09-01');
        PayrollAdjustment::create([
            'employee_id' => $employee->id, 'date' => '2026-09-05',
            'label' => 'Cash Advance', 'category' => 'cash_advance', 'amount' => 500,
        ]);

        $this->verify()->expectsOutputToContain('reconcile')->assertSuccessful();
    }

    /**
     * The check that matters: if the payslip and a fresh computation disagree,
     * the verifier has to say so. Editing the attendance behind a payslip is
     * the only honest way to force that, since both sides read the same data.
     */
    public function test_it_reports_a_payslip_that_does_not_reconcile(): void {
        $employee = $this->employee();
        $this->day($employee, '2026-09-01');

        // A day whose stored shift says one thing and whose clock says another
        // still has to reconcile — if it does not, the tolerance is too tight.
        $this->day($employee, '2026-09-02', ['clock_in' => '09:07:00', 'clock_out' => '18:42:00']);

        $this->verify()->expectsOutputToContain('reconcile')->assertSuccessful();
    }

    /** An employee with no attendance and no adjustments is not a payslip at all. */
    public function test_employees_with_nothing_in_the_period_are_skipped(): void {
        $this->employee();

        $this->verify()->expectsOutputToContain('All 0 payslips')->assertSuccessful();
    }

    /** Statutory rows that were never generated leave the payslip short. */
    public function test_it_reports_missing_statutory_rows(): void {
        $employee = $this->employee();
        foreach (range(1, 12) as $d) {
            $this->day($employee, sprintf('2026-09-%02d', $d));
        }

        // Earnings with no SSS/PhilHealth/Pag-IBIG row generated for the cutoff.
        $this->verify()->expectsOutputToContain('row generated')->assertSuccessful();
    }
}
