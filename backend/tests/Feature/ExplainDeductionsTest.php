<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Itemises a cutoff's deduction band so a surprising column can be traced. */
class ExplainDeductionsTest extends TestCase {
    use RefreshDatabase;

    /** Christopher T. Navarro's Aug 1-15 2026, the row this command was written for. */
    private function employee(): Employee {
        $employee = Employee::factory()->for(Branch::factory())->create([
            'employee_code' => 'EMP-0016', 'full_name' => 'Christopher T. Navarro',
            'short_name' => 'Christoph', 'daily_basic_rate' => 505,
            'shift_start' => '08:00:00', 'shift_end' => '17:00:00',
        ]);
        AttendanceRecord::create([
            'employee_id' => $employee->id, 'work_date' => '2026-08-03',
            'shift_start' => '08:00:00', 'shift_end' => '17:00:00',
            'clock_in' => '08:00:00', 'clock_out' => '17:00:00', 'status' => 'approved',
        ]);

        $admin = User::factory()->create();
        foreach ([
            ['deduction', 'Authorized Deduction', -1470.00],
            ['deduction', 'Penalty Late (2 days)', -150.00],
            ['cash_advance', 'Cash Advance', -500.00],
            ['allowance', 'Rice Allowance', 920.00],
        ] as [$category, $label, $amount]) {
            PayrollAdjustment::create([
                'employee_id' => $employee->id, 'date' => '2026-08-10', 'label' => $label,
                'category' => $category, 'amount' => $amount, 'paid' => false, 'created_by' => $admin->id,
            ]);
        }

        return $employee;
    }

    public function test_it_names_the_column_each_row_lands_in(): void {
        $this->employee();

        $this->artisan('payroll:explain-deductions EMP-0016 --month=2026-08 --period=first')
            ->expectsOutputToContain('I  CA etc (other authorised)')
            ->expectsOutputToContain('H  Penalty Lates (by label)')
            ->expectsOutputToContain('I  CA etc (cash advance)')
            ->expectsOutputToContain('O  Rice Allowance (by label)')
            ->assertSuccessful();
    }

    /** The sum that prompted the question: 1,470 + 500 = 1,970, not 1,470. */
    public function test_it_breaks_the_ca_column_into_its_parts(): void {
        $this->employee();

        $this->artisan('payroll:explain-deductions EMP-0016 --month=2026-08 --period=first')
            ->expectsOutputToContain('(cash advance 500.00 + other authorised 1,470.00)')
            ->expectsOutputToContain('H + I = J, the band foots')
            ->assertSuccessful();
    }

    public function test_it_finds_an_employee_by_name(): void {
        $this->employee();

        $this->artisan('payroll:explain-deductions Navarro --month=2026-08 --period=first')
            ->expectsOutputToContain('Christopher T. Navarro')
            ->assertSuccessful();
    }

    public function test_an_unknown_employee_fails_clearly(): void {
        $this->artisan('payroll:explain-deductions Nobody --month=2026-08')
            ->expectsOutputToContain('No employee matched "Nobody".')
            ->assertFailed();
    }

    public function test_a_bad_period_fails_clearly(): void {
        $this->employee();

        $this->artisan('payroll:explain-deductions EMP-0016 --month=2026-08 --period=middle')
            ->expectsOutputToContain('--period must be first, second or whole.')
            ->assertFailed();
    }

    public function test_an_empty_window_says_so(): void {
        $this->employee();

        $this->artisan('payroll:explain-deductions EMP-0016 --month=2026-07 --period=first')
            ->expectsOutputToContain('No adjustment rows in this window at all.')
            ->assertSuccessful();
    }
}
