<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\StatutoryDeductionController;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A flat charge per late day, on one row for the cutoff.
 *
 * This generator existed once and was removed (3fb2e53) for charging the same
 * days twice — it wrote its own figure beside a lump the office had already
 * typed. The double-charge guard is the point of most of these tests.
 */
class LatePenaltyGeneratorTest extends TestCase {
    use RefreshDatabase;

    private function employee(): Employee {
        return Employee::factory()->for(Branch::factory())->create([
            'daily_basic_rate' => 505, 'shift_start' => '09:00:00', 'shift_end' => '18:00:00',
        ]);
    }

    /** @param list<string> $days each "d|clock_in" */
    private function attendance(Employee $employee, array $days): void {
        foreach ($days as $spec) {
            [$day, $in] = explode('|', $spec);
            AttendanceRecord::create([
                'employee_id' => $employee->id, 'work_date' => "2026-08-{$day}",
                'shift_start' => '09:00:00', 'shift_end' => '18:00:00',
                'clock_in' => $in, 'clock_out' => '18:00:00', 'status' => 'approved',
            ]);
        }
    }

    private function generate(User $admin): void {
        $this->actingAs($admin)
            ->postJson('/api/admin/payroll/period/statutory?month=2026-08&period=second')->assertOk();
    }

    private function penaltyRows(Employee $employee) {
        return PayrollAdjustment::where('employee_id', $employee->id)
            ->whereIn('category', ['penalty_late', 'deduction'])->get();
    }

    public function test_each_late_day_adds_the_penalty_on_a_single_row(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();
        // Three late mornings and one on time.
        $this->attendance($employee, ['17|09:20:00', '18|09:05:00', '19|09:00:00', '20|09:41:00']);

        $this->generate($admin);

        $rows = $this->penaltyRows($employee);
        $this->assertCount(1, $rows, 'one row for the cutoff, not one per late day');
        $this->assertSame(-225.00, round((float) $rows->first()->amount, 2)); // 3 x 75
        $this->assertSame('Penalty Late (3 days)', $rows->first()->label);
    }

    public function test_one_late_day_reads_in_the_singular(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();
        $this->attendance($employee, ['17|09:20:00', '18|09:00:00']);

        $this->generate($admin);

        $this->assertSame('Penalty Late (1 day)', $this->penaltyRows($employee)->first()->label);
    }

    /** The type the office files it under, so generated and typed rows match. */
    public function test_it_is_filed_as_an_authorized_deduction(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();
        $this->attendance($employee, ['17|09:20:00']);

        $this->generate($admin);

        $this->assertSame('deduction', $this->penaltyRows($employee)->first()->category);
    }

    public function test_no_late_days_writes_nothing(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();
        $this->attendance($employee, ['17|08:55:00', '18|09:00:00']);

        $this->generate($admin);

        $this->assertCount(0, $this->penaltyRows($employee));
    }

    /**
     * The failure that removed this generator last time: it must not add its
     * figure on top of one the office has already typed for the same days.
     */
    public function test_it_leaves_a_hand_typed_late_charge_alone(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();
        $this->attendance($employee, ['17|09:20:00', '18|09:05:00']);

        PayrollAdjustment::create([
            'employee_id' => $employee->id, 'date' => '2026-08-31', 'label' => 'Penalty Late',
            'category' => 'deduction', 'amount' => -525.00, 'paid' => false,
            'reason' => 'Counted in the office', 'created_by' => $admin->id,
        ]);

        $this->generate($admin);

        $rows = $this->penaltyRows($employee);
        $this->assertCount(1, $rows, 'no second row may appear beside the typed one');
        $this->assertSame(-525.00, round((float) $rows->first()->amount, 2));
    }

    /**
     * Rows the previous generator (44653a1) wrote are still in the database —
     * reverting it removed the button, not the data. They are one row per late
     * day, typed penalty_late, labelled "Penalty Late (Aug 7)", and carry their
     * own reason string. Adding to them would charge the same days twice, so
     * they count as somebody else's and the period is left alone.
     */
    public function test_it_leaves_rows_from_the_old_generator_alone(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();
        $this->attendance($employee, ['17|09:20:00', '18|09:05:00']);

        foreach ([['17', 'Aug 17'], ['18', 'Aug 18']] as [$day, $name]) {
            PayrollAdjustment::create([
                'employee_id' => $employee->id, 'date' => "2026-08-{$day}",
                'label' => "Penalty Late ({$name})", 'category' => 'penalty_late',
                'amount' => -75.00, 'paid' => false,
                'reason' => 'Auto-generated late penalty', 'created_by' => $admin->id,
            ]);
        }

        $this->generate($admin);

        $rows = $this->penaltyRows($employee);
        $this->assertCount(2, $rows, 'no third row may be added on top of the old two');
        $this->assertSame(-150.00, round((float) $rows->sum('amount'), 2));
    }

    /** An unrelated authorized deduction must not block the penalty. */
    public function test_an_ordinary_authorized_deduction_does_not_block_it(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();
        $this->attendance($employee, ['17|09:20:00', '18|09:05:00']);

        PayrollAdjustment::create([
            'employee_id' => $employee->id, 'date' => '2026-08-31', 'label' => 'Uniform',
            'category' => 'deduction', 'amount' => -300.00, 'paid' => false,
            'reason' => 'Agreed with the employee', 'created_by' => $admin->id,
        ]);

        $this->generate($admin);

        $penalty = $this->penaltyRows($employee)->firstWhere('reason', StatutoryDeductionController::AUTO_REASON);
        $this->assertNotNull($penalty, 'the uniform charge is not a late charge');
        $this->assertSame(-150.00, round((float) $penalty->amount, 2));
    }

    /** Re-running after correcting attendance moves the amount and the count. */
    public function test_rerunning_corrects_its_own_row_in_place(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();
        $this->attendance($employee, ['17|09:20:00', '18|09:05:00', '19|09:41:00']);
        $this->generate($admin);
        $this->assertSame(-225.00, round((float) $this->penaltyRows($employee)->first()->amount, 2));

        // The office corrects one morning: that day was not late after all.
        AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('work_date', '2026-08-19')->firstOrFail()->update(['clock_in' => '08:58:00']);

        $this->generate($admin);

        $rows = $this->penaltyRows($employee);
        $this->assertCount(1, $rows);
        $this->assertSame(-150.00, round((float) $rows->first()->amount, 2));
        $this->assertSame('Penalty Late (2 days)', $rows->first()->label);
    }

    /** The summary workbook must still see it as a late penalty, not a lump. */
    public function test_it_still_lands_in_the_penalty_lates_column(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();
        $this->attendance($employee, ['17|09:20:00', '18|09:05:00']);
        $this->generate($admin);

        $totals = $this->actingAs($admin)
            ->getJson("/api/admin/employees/{$employee->id}/payslip?month=2026-08&period=second")
            ->assertOk()->json('totals');

        $this->assertSame(150.0, (float) $totals['penalty_late']);
    }
}
