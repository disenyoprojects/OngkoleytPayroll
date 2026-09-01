<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollAdjustmentTest extends TestCase {
    use RefreshDatabase;

    private function workedEmployee(): Employee {
        $employee = Employee::factory()->for(Branch::factory())->create([
            'shift_start' => '08:00:00', 'shift_end' => '17:00:00', 'daily_basic_rate' => null,
        ]);
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-07-20', 'shift_start' => '08:00:00', 'shift_end' => '17:00:00',
            'clock_in' => '08:00:00', 'clock_out' => '17:00:00',
        ]);
        return $employee;
    }

    public function test_paid_cash_adjustment_raises_total_but_not_net_to_release(): void {
        $admin = User::factory()->create();
        $employee = $this->workedEmployee();

        $this->actingAs($admin)->postJson("/api/admin/employees/{$employee->id}/adjustments", [
            'date' => '2026-07-20', 'label' => 'Night shift bonus', 'category' => 'cash_on_hand',
            'amount' => 200, 'paid' => true,
        ])->assertCreated();

        $slip = $this->actingAs($admin)
            ->getJson("/api/admin/employees/{$employee->id}/payslip?month=2026-07&period=second")
            ->assertOk()->json();

        // One worked 8h day at ₱505 gross.
        $this->assertEquals(505.0, $slip['totals']['gross']);
        $this->assertEquals(200.0, $slip['totals']['adjustments']);
        $this->assertEquals(705.0, $slip['totals']['total_salary']);   // includes the 200
        $this->assertEquals(200.0, $slip['totals']['paid']);
        $this->assertEquals(505.0, $slip['totals']['net_to_release']); // 200 already handed over
        $this->assertCount(1, $slip['adjustments']);
    }

    public function test_unpaid_adjustment_increases_net_to_release(): void {
        $admin = User::factory()->create();
        $employee = $this->workedEmployee();

        $this->actingAs($admin)->postJson("/api/admin/employees/{$employee->id}/adjustments", [
            'date' => '2026-07-21', 'label' => 'Rice allowance', 'category' => 'allowance',
            'amount' => 720, 'paid' => false,
        ])->assertCreated();

        $slip = $this->actingAs($admin)
            ->getJson("/api/admin/employees/{$employee->id}/payslip?month=2026-07&period=second")
            ->assertOk()->json();

        $this->assertEquals(1225.0, $slip['totals']['total_salary']);   // 505 + 720
        $this->assertEquals(0.0, $slip['totals']['paid']);
        $this->assertEquals(1225.0, $slip['totals']['net_to_release']);
    }

    public function test_deduction_amount_is_stored_negative_and_subtracts(): void {
        $admin = User::factory()->create();
        $employee = $this->workedEmployee();

        // Admin enters a positive amount for a Deduction; it must subtract.
        $created = $this->actingAs($admin)->postJson("/api/admin/employees/{$employee->id}/adjustments", [
            'date' => '2026-07-20', 'label' => 'Cash advance', 'category' => 'deduction',
            'amount' => 300, 'paid' => false,
        ])->assertCreated()->json();

        $this->assertEquals(-300.0, (float) $created['amount']);

        $slip = $this->actingAs($admin)
            ->getJson("/api/admin/employees/{$employee->id}/payslip?month=2026-07&period=second")
            ->assertOk()->json();

        $this->assertEquals(205.0, $slip['totals']['total_salary']);      // 505 − 300
        $this->assertEquals(205.0, $slip['totals']['net_to_release']);
    }

    public function test_bonus_amount_is_stored_positive_even_if_negative_entered(): void {
        $admin = User::factory()->create();
        $employee = $this->workedEmployee();

        $created = $this->actingAs($admin)->postJson("/api/admin/employees/{$employee->id}/adjustments", [
            'date' => '2026-07-20', 'label' => 'Bonus', 'category' => 'bonus',
            'amount' => -200, 'paid' => false,
        ])->assertCreated()->json();

        $this->assertEquals(200.0, (float) $created['amount']);
    }

    public function test_statutory_category_subtracts_and_auto_labels(): void {
        $admin = User::factory()->create();
        $employee = $this->workedEmployee();

        // No label typed — SSS should subtract and label itself "SSS".
        $created = $this->actingAs($admin)->postJson("/api/admin/employees/{$employee->id}/adjustments", [
            'date' => '2026-07-20', 'category' => 'sss', 'amount' => 425, 'paid' => false,
        ])->assertCreated()->json();

        $this->assertEquals(-425.0, (float) $created['amount']);
        $this->assertSame('SSS', $created['label']);

        $slip = $this->actingAs($admin)
            ->getJson("/api/admin/employees/{$employee->id}/payslip?month=2026-07&period=second")
            ->assertOk()->json();

        $this->assertEquals(80.0, $slip['totals']['net_to_release']); // 505 − 425
        $this->assertContains('SSS', array_column($slip['slip']['deductions'], 'label'));
    }

    public function test_adjustment_outside_period_is_excluded(): void {
        $admin = User::factory()->create();
        $employee = $this->workedEmployee();

        $this->actingAs($admin)->postJson("/api/admin/employees/{$employee->id}/adjustments", [
            'date' => '2026-07-05', 'label' => 'First-half bonus', 'category' => 'bonus',
            'amount' => 300, 'paid' => false,
        ])->assertCreated();

        $slip = $this->actingAs($admin)
            ->getJson("/api/admin/employees/{$employee->id}/payslip?month=2026-07&period=second")
            ->assertOk()->json();

        $this->assertCount(0, $slip['adjustments']);
        $this->assertEquals(0.0, $slip['totals']['adjustments']);
    }

    /** Correcting a typed amount without deleting and re-entering the row. */
    public function test_edit_changes_the_amount_and_keeps_the_deduction_sign(): void {
        $admin = User::factory()->create();
        $employee = $this->workedEmployee();

        $id = $this->actingAs($admin)->postJson("/api/admin/employees/{$employee->id}/adjustments", [
            'date' => '2026-07-20', 'label' => 'Penalty Late', 'category' => 'penalty_late',
            'amount' => 525, 'paid' => false,
        ])->assertCreated()->json('id');

        // Typed positive, as the form does — it must still subtract.
        $this->actingAs($admin)->patchJson("/api/admin/adjustments/{$id}", ['amount' => 150])->assertOk();

        $slip = $this->actingAs($admin)
            ->getJson("/api/admin/employees/{$employee->id}/payslip?month=2026-07&period=second")
            ->assertOk()->json();
        $this->assertEqualsWithDelta(150.00, $slip['totals']['penalty_late'], 0.001);
        $this->assertEqualsWithDelta(355.00, $slip['totals']['net_to_release'], 0.001); // 505 − 150
    }

    /** Setting an entry to 0 cancels it while keeping the record of the day. */
    public function test_edit_to_zero_cancels_the_charge(): void {
        $admin = User::factory()->create();
        $employee = $this->workedEmployee();

        $id = $this->actingAs($admin)->postJson("/api/admin/employees/{$employee->id}/adjustments", [
            'date' => '2026-07-20', 'label' => 'Penalty Late', 'category' => 'penalty_late',
            'amount' => 75, 'paid' => false,
        ])->assertCreated()->json('id');

        $this->actingAs($admin)->patchJson("/api/admin/adjustments/{$id}", ['amount' => 0])->assertOk();

        $slip = $this->actingAs($admin)
            ->getJson("/api/admin/employees/{$employee->id}/payslip?month=2026-07&period=second")
            ->assertOk()->json();
        $this->assertEqualsWithDelta(0.0, $slip['totals']['penalty_late'], 0.001);
        $this->assertEqualsWithDelta(505.00, $slip['totals']['net_to_release'], 0.001);
        $this->assertCount(1, $slip['adjustments']); // the row is still there
    }

    public function test_delete_removes_the_adjustment(): void {
        $admin = User::factory()->create();
        $employee = $this->workedEmployee();

        $id = $this->actingAs($admin)->postJson("/api/admin/employees/{$employee->id}/adjustments", [
            'date' => '2026-07-20', 'label' => 'X', 'category' => 'other', 'amount' => 50, 'paid' => false,
        ])->json('id');

        $this->actingAs($admin)->deleteJson("/api/admin/adjustments/{$id}")->assertOk();
        $this->assertDatabaseMissing('payroll_adjustments', ['id' => $id]);
    }

    public function test_adjustment_endpoints_require_authentication(): void {
        $employee = $this->workedEmployee();
        $this->postJson("/api/admin/employees/{$employee->id}/adjustments", [])->assertStatus(401);
        $this->patchJson('/api/admin/adjustments/1', ['amount' => 10])->assertStatus(401);
        $this->deleteJson('/api/admin/adjustments/1')->assertStatus(401);
    }

    /**
     * A deduction ticked "already paid" used to cancel itself out of Net to
     * Release: the register handed over ₱75 more than the payslip showed.
     */
    public function test_a_deduction_marked_paid_does_not_raise_net_to_release(): void {
        $admin = User::factory()->create();
        $employee = $this->workedEmployee();

        $this->actingAs($admin)->postJson("/api/admin/employees/{$employee->id}/adjustments", [
            'date' => '2026-07-20', 'label' => 'Penalty Late', 'category' => 'penalty_late',
            'amount' => 75, 'paid' => true,
        ])->assertCreated();

        $slip = $this->actingAs($admin)
            ->getJson("/api/admin/employees/{$employee->id}/payslip?month=2026-07&period=second")
            ->assertOk()->json();

        // One 8h day at ₱505, less the ₱75 penalty, however the flag is set.
        $this->assertEqualsWithDelta(0.0, $slip['totals']['paid'], 0.001);
        $this->assertEqualsWithDelta(430.00, $slip['totals']['net_to_release'], 0.001);
        // And the two documents agree, which is the point.
        $this->assertEqualsWithDelta($slip['slip']['net'], $slip['totals']['net_to_release'], 0.001);
    }

    /** An allowance handed over in cash still nets out — the flag keeps working where it belongs. */
    public function test_a_paid_allowance_still_nets_out(): void {
        $admin = User::factory()->create();
        $employee = $this->workedEmployee();

        $this->actingAs($admin)->postJson("/api/admin/employees/{$employee->id}/adjustments", [
            'date' => '2026-07-20', 'label' => 'Rice Allowance', 'category' => 'rice_allowance',
            'amount' => 300, 'paid' => true,
        ])->assertCreated();

        $slip = $this->actingAs($admin)
            ->getJson("/api/admin/employees/{$employee->id}/payslip?month=2026-07&period=second")
            ->assertOk()->json();

        $this->assertEqualsWithDelta(300.00, $slip['totals']['paid'], 0.001);
        $this->assertEqualsWithDelta(805.00, $slip['totals']['total_salary'], 0.001);
        $this->assertEqualsWithDelta(505.00, $slip['totals']['net_to_release'], 0.001);
    }

    /** The employee's slip shows one "Authorized Deduction"; the office sheet keeps the breakdown. */
    public function test_the_late_penalty_prints_as_an_authorized_deduction_on_the_payslip(): void {
        $admin = User::factory()->create();
        $employee = $this->workedEmployee();

        foreach ([
            ['penalty_late', 'Penalty Late', 75],
            ['deduction', 'Authorized Deduction', 500],
            ['cash_advance', 'Cash Advance', 1000],
        ] as [$category, $label, $amount]) {
            $this->actingAs($admin)->postJson("/api/admin/employees/{$employee->id}/adjustments", [
                'date' => '2026-07-20', 'label' => $label, 'category' => $category,
                'amount' => $amount, 'paid' => false,
            ])->assertCreated();
        }

        $slip = $this->actingAs($admin)
            ->getJson("/api/admin/employees/{$employee->id}/payslip?month=2026-07&period=second")
            ->assertOk()->json();

        $labels = array_column($slip['slip']['deductions'], 'label');
        $this->assertNotContains('Penalty Late', $labels);
        // The 75 is folded into the 500, printed as one line.
        $this->assertContains('Authorized Deduction', $labels);
        $this->assertSame(1, count(array_keys($labels, 'Authorized Deduction', true)));
        $authorised = collect($slip['slip']['deductions'])->firstWhere('label', 'Authorized Deduction');
        $this->assertEqualsWithDelta(575.00, $authorised['amount'], 0.001);
        // The cash advance keeps its own line — only the penalty is folded in.
        $this->assertContains('Cash Advance', $labels);

        // The office columns still break it out, and no pay figure moved:
        // 505 gross − 75 − 500 − 1000 = −1070.
        $this->assertEqualsWithDelta(75.00, $slip['totals']['penalty_late'], 0.001);
        $this->assertEqualsWithDelta(1000.00, $slip['totals']['cash_advance'], 0.001);
        $this->assertEqualsWithDelta(1575.00, $slip['totals']['auth_deductions'], 0.001);
        $this->assertEqualsWithDelta(-1070.00, $slip['totals']['net_to_release'], 0.001);
    }
}
