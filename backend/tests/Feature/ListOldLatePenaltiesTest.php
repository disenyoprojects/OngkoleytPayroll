<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The clean-up for rows left by the generator removed in 3fb2e53. */
class ListOldLatePenaltiesTest extends TestCase {
    use RefreshDatabase;

    private function employeeWithTwoLates(): Employee {
        $employee = Employee::factory()->for(Branch::factory())->create([
            'short_name' => 'Ruby', 'daily_basic_rate' => 505,
            'shift_start' => '09:00:00', 'shift_end' => '18:00:00',
        ]);

        foreach (['17|09:20:00', '18|09:05:00'] as $spec) {
            [$day, $in] = explode('|', $spec);
            AttendanceRecord::create([
                'employee_id' => $employee->id, 'work_date' => "2026-08-{$day}",
                'shift_start' => '09:00:00', 'shift_end' => '18:00:00',
                'clock_in' => $in, 'clock_out' => '18:00:00', 'status' => 'approved',
            ]);
        }

        foreach ([['17', 'Aug 17'], ['18', 'Aug 18']] as [$day, $name]) {
            PayrollAdjustment::create([
                'employee_id' => $employee->id, 'date' => "2026-08-{$day}",
                'label' => "Penalty Late ({$name})", 'category' => 'penalty_late',
                'amount' => -75.00, 'paid' => false,
                'reason' => 'Auto-generated late penalty',
                'created_by' => User::factory()->create()->id,
            ]);
        }

        return $employee;
    }

    public function test_it_lists_the_rows_and_changes_nothing(): void {
        $this->employeeWithTwoLates();

        $this->artisan('payroll:old-late-penalties')
            ->expectsOutputToContain('Penalty Late (Aug 17)')
            ->expectsOutputToContain('Penalty Late (Aug 18)')
            ->expectsOutputToContain('Nothing was changed.')
            ->assertSuccessful();

        $this->assertSame(2, PayrollAdjustment::count());
    }

    /** The comparison the office needs before deciding: same money either way. */
    public function test_it_shows_what_the_new_generator_would_write(): void {
        $this->employeeWithTwoLates();

        $this->artisan('payroll:old-late-penalties')
            ->expectsOutputToContain('old total 150.00  ->  new generator would write 150.00')
            ->assertSuccessful();
    }

    public function test_declining_the_confirmation_deletes_nothing(): void {
        $this->employeeWithTwoLates();

        $this->artisan('payroll:old-late-penalties --delete')
            ->expectsConfirmation('Delete 2 rows?', 'no')
            ->expectsOutputToContain('Left alone.')
            ->assertSuccessful();

        $this->assertSame(2, PayrollAdjustment::count());
    }

    public function test_confirming_removes_them(): void {
        $this->employeeWithTwoLates();

        $this->artisan('payroll:old-late-penalties --delete')
            ->expectsConfirmation('Delete 2 rows?', 'yes')
            ->assertSuccessful();

        $this->assertSame(0, PayrollAdjustment::count());
    }

    /** A row somebody typed is not the old generator's and must survive. */
    public function test_it_ignores_hand_entered_penalties(): void {
        $employee = $this->employeeWithTwoLates();
        PayrollAdjustment::create([
            'employee_id' => $employee->id, 'date' => '2026-08-20', 'label' => 'Penalty Late',
            'category' => 'deduction', 'amount' => -525.00, 'paid' => false,
            'reason' => 'Counted in the office', 'created_by' => User::factory()->create()->id,
        ]);

        $this->artisan('payroll:old-late-penalties --delete')
            ->expectsConfirmation('Delete 2 rows?', 'yes')
            ->assertSuccessful();

        $this->assertSame(1, PayrollAdjustment::count());
        $this->assertSame('Counted in the office', PayrollAdjustment::first()->reason);
    }

    public function test_the_month_filter_narrows_the_list(): void {
        $this->employeeWithTwoLates();

        $this->artisan('payroll:old-late-penalties --month=2026-07')
            ->expectsOutputToContain('No leftover rows found.')
            ->assertSuccessful();

        $this->assertSame(2, PayrollAdjustment::count());
    }
}
