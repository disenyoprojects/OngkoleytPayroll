<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\User;
use App\Http\Controllers\Admin\StatutoryDeductionController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The SSS bracket is read from wages plus allowances. The generator did that
 * and the attendance observer did not, so editing a clock time re-derived the
 * contribution from wages alone and dropped the employee a bracket — Ruby Rose
 * Anudon's Aug 16-31 2026 fell from P375 to P250 after two attendance
 * corrections, against a manager-verified P375.
 */
class SssBasisConsistencyTest extends TestCase {
    use RefreshDatabase;

    private function employee(): Employee {
        return Employee::factory()->for(Branch::factory())->create([
            'shift_start' => '11:00:00', 'shift_end' => '20:00:00', 'daily_basic_rate' => null,
        ]);
    }

    /** Nine worked days at ~P505 plus a P2,700 rice allowance: Ruby's cutoff. */
    private function seedCutoff(Employee $employee, User $admin): void {
        foreach (['17', '18', '19', '20', '24', '25', '26', '28', '31'] as $day) {
            AttendanceRecord::create([
                'employee_id' => $employee->id, 'work_date' => "2026-08-{$day}",
                'shift_start' => '11:00:00', 'shift_end' => '20:00:00',
                'clock_in' => '11:00:00', 'clock_out' => '20:00:00', 'status' => 'approved',
            ]);
        }

        PayrollAdjustment::create([
            'employee_id' => $employee->id, 'date' => '2026-08-16', 'label' => 'Rice allowance',
            'category' => 'rice_allowance', 'amount' => 2700.00, 'paid' => false,
            'created_by' => $admin->id,
        ]);
    }

    private function sssRow(Employee $employee): ?PayrollAdjustment {
        return PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'sss')->first();
    }

    public function test_the_generator_reads_the_bracket_from_wages_plus_allowances(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();
        $this->seedCutoff($employee, $admin);

        $this->actingAs($admin)->postJson('/api/admin/payroll/period/statutory', [
            'month' => '2026-08', 'period' => 'second',
        ])->assertOk();

        // 9 x P505 = P4,545 wages + P2,700 allowance = P7,245 -> P7,249.99 bracket.
        $this->assertSame(-350.00, round((float) $this->sssRow($employee)->amount, 2));
    }

    /**
     * The regression itself: an attendance edit must not re-derive the bracket
     * from wages alone. Before the fix this fell to the P250 floor.
     */
    public function test_an_attendance_edit_keeps_the_allowance_in_the_basis(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();
        $this->seedCutoff($employee, $admin);

        $this->actingAs($admin)->postJson('/api/admin/payroll/period/statutory', [
            'month' => '2026-08', 'period' => 'second',
        ])->assertOk();
        // Touch a clock time, exactly as correcting a record does. This adds a
        // little overtime, so the amount is allowed to move — what must not
        // happen is the P2,700 allowance dropping out and the bracket falling
        // to the P250 floor, which is what the old observer did.
        $record = AttendanceRecord::where('employee_id', $employee->id)->firstOrFail();
        $record->update(['clock_out' => '20:15:00']);

        $after = round((float) $this->sssRow($employee)->amount, 2);
        $this->assertNotSame(-250.00, $after, 'the allowance was dropped from the basis');
        $this->assertSame(-375.00, $after); // 4,564.73 wages + 2,700 = 7,264.73
    }

    /** A row somebody typed by hand is still never overwritten. */
    public function test_a_hand_entered_row_is_left_alone(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();
        $this->seedCutoff($employee, $admin);

        PayrollAdjustment::create([
            'employee_id' => $employee->id, 'date' => '2026-08-31', 'label' => 'SSS',
            'category' => 'sss', 'amount' => -999.00, 'paid' => false,
            'reason' => 'Agreed with the employee', 'created_by' => $admin->id,
        ]);

        $record = AttendanceRecord::where('employee_id', $employee->id)->firstOrFail();
        $record->update(['clock_out' => '20:15:00']);

        $this->assertSame(-999.00, round((float) $this->sssRow($employee)->amount, 2));
    }

    /** Both paths must agree; that they once did not is the whole bug. */
    public function test_regenerating_after_an_edit_changes_nothing(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();
        $this->seedCutoff($employee, $admin);

        $this->actingAs($admin)->postJson('/api/admin/payroll/period/statutory', [
            'month' => '2026-08', 'period' => 'second',
        ])->assertOk();

        $record = AttendanceRecord::where('employee_id', $employee->id)->firstOrFail();
        $record->update(['clock_out' => '20:15:00']);
        $afterEdit = round((float) $this->sssRow($employee)->amount, 2);

        $this->actingAs($admin)->postJson('/api/admin/payroll/period/statutory', [
            'month' => '2026-08', 'period' => 'second',
        ])->assertOk();

        $this->assertSame($afterEdit, round((float) $this->sssRow($employee)->amount, 2));
        $this->assertSame(StatutoryDeductionController::AUTO_REASON, $this->sssRow($employee)->reason);
    }

    /**
     * The client's own worked example, end to end. First cutoff pays the
     * bracket on its own earnings; the second pays the balance of the month's.
     *
     *   Aug 1-15   8,322.23 -> 425.00
     *   Aug 16-31  7,621.12
     *   month     15,943.35 -> 800.00, less 425.00 already taken = 375.00
     */
    public function test_the_second_cutoff_collects_the_balance_of_the_months_bracket(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();

        // Wages are immaterial here; the basis is driven by the allowances, so
        // the two cutoffs land on the client's figures exactly.
        PayrollAdjustment::create([
            'employee_id' => $employee->id, 'date' => '2026-08-15', 'label' => 'Allowance',
            'category' => 'allowance', 'amount' => 8322.23, 'paid' => false, 'created_by' => $admin->id,
        ]);
        PayrollAdjustment::create([
            'employee_id' => $employee->id, 'date' => '2026-08-16', 'label' => 'Allowance',
            'category' => 'allowance', 'amount' => 7621.12, 'paid' => false, 'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->postJson('/api/admin/payroll/period/statutory', [
            'month' => '2026-08', 'period' => 'first',
        ])->assertOk();
        $first = PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'sss')
            ->whereDate('date', '<=', '2026-08-15')->first();
        $this->assertSame(-425.00, round((float) $first->amount, 2));

        $this->actingAs($admin)->postJson('/api/admin/payroll/period/statutory', [
            'month' => '2026-08', 'period' => 'second',
        ])->assertOk();
        $second = PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'sss')
            ->whereDate('date', '>=', '2026-08-16')->first();
        $this->assertSame(-375.00, round((float) $second->amount, 2));

        // 425 + 375 = the month's 800.
        $this->assertSame(-800.00, round((float) PayrollAdjustment::where('employee_id', $employee->id)
            ->where('category', 'sss')->sum('amount'), 2));
    }

    /** A month worth less than the first half already took collects nothing. */
    public function test_the_second_cutoff_never_refunds(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();

        PayrollAdjustment::create([
            'employee_id' => $employee->id, 'date' => '2026-08-15', 'label' => 'SSS',
            'category' => 'sss', 'amount' => -900.00, 'paid' => false,
            'reason' => StatutoryDeductionController::AUTO_REASON, 'created_by' => $admin->id,
        ]);
        PayrollAdjustment::create([
            'employee_id' => $employee->id, 'date' => '2026-08-20', 'label' => 'Allowance',
            'category' => 'allowance', 'amount' => 6000.00, 'paid' => false, 'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->postJson('/api/admin/payroll/period/statutory', [
            'month' => '2026-08', 'period' => 'second',
        ])->assertOk();

        $second = PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'sss')
            ->whereDate('date', '>=', '2026-08-16')->first();
        $this->assertSame(0.0, round((float) $second->amount, 2));
    }
}
