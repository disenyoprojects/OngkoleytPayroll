<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\PayrollSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Generate Penalty Lates: one row per late day, still the office's to excuse. */
class PenaltyLateGeneratorTest extends TestCase {
    use RefreshDatabase;

    private function employeeWithDays(array $days): Employee {
        $employee = Employee::factory()->for(Branch::factory())->create([
            'shift_start' => '08:00:00', 'shift_end' => '17:00:00', 'daily_basic_rate' => 505,
        ]);
        foreach ($days as $date => $clockIn) {
            AttendanceRecord::factory()->for($employee)->create([
                'work_date' => $date, 'shift_start' => '08:00:00', 'shift_end' => '17:00:00',
                'clock_in' => $clockIn, 'clock_out' => '17:00:00', 'status' => 'approved',
            ]);
        }

        return $employee;
    }

    private function generate(User $admin) {
        return $this->actingAs($admin)
            ->postJson('/api/admin/payroll/period/penalty-lates?month=2026-08&period=first');
    }

    public function test_two_late_days_are_charged_seventy_five_each(): void {
        $admin = User::factory()->create();
        $employee = $this->employeeWithDays([
            '2026-08-03' => '08:15:00',  // late
            '2026-08-04' => '08:00:00',  // on time
            '2026-08-05' => '08:20:00',  // late
        ]);

        $this->generate($admin)->assertOk()
            ->assertJsonPath('generated', 2)
            ->assertJsonPath('amount', 75);

        $rows = PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'penalty_late')->get();
        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(-150.00, $rows->sum('amount'), 0.001);

        // Which is what the office reads in the Penalty Lates column.
        $slip = $this->actingAs($admin)
            ->getJson("/api/admin/employees/{$employee->id}/payslip?month=2026-08&period=first")
            ->assertOk()->json();
        $this->assertEqualsWithDelta(150.00, $slip['totals']['penalty_late'], 0.001);
    }

    public function test_the_amount_follows_the_setting(): void {
        $admin = User::factory()->create();
        PayrollSetting::current()->update(['late_penalty_amount' => 120]);
        $employee = $this->employeeWithDays(['2026-08-03' => '08:15:00']);

        $this->generate($admin)->assertOk()->assertJsonPath('amount', 120);
        $this->assertEqualsWithDelta(
            -120.00,
            (float) PayrollAdjustment::where('employee_id', $employee->id)->value('amount'),
            0.001,
        );
    }

    public function test_rerunning_does_not_double_charge(): void {
        $admin = User::factory()->create();
        $employee = $this->employeeWithDays(['2026-08-03' => '08:15:00']);

        $this->generate($admin)->assertOk()->assertJsonPath('generated', 1);
        $this->generate($admin)->assertOk()->assertJsonPath('generated', 0)->assertJsonPath('skipped', 1);

        $this->assertSame(1, PayrollAdjustment::where('employee_id', $employee->id)->count());
    }

    /** Excusing a late means setting it to 0 — and a re-run must not undo that. */
    public function test_an_excused_late_stays_excused_when_regenerated(): void {
        $admin = User::factory()->create();
        $employee = $this->employeeWithDays([
            '2026-08-03' => '08:15:00',
            '2026-08-05' => '08:20:00',
        ]);
        $this->generate($admin)->assertOk();

        $excused = PayrollAdjustment::where('employee_id', $employee->id)->orderBy('date')->first();
        $this->actingAs($admin)->patchJson("/api/admin/adjustments/{$excused->id}", ['amount' => 0])
            ->assertOk()->assertJsonPath('amount', '0.00');

        $this->generate($admin)->assertOk()->assertJsonPath('generated', 0);

        $slip = $this->actingAs($admin)
            ->getJson("/api/admin/employees/{$employee->id}/payslip?month=2026-08&period=first")
            ->assertOk()->json();
        // One excused, one charged.
        $this->assertEqualsWithDelta(75.00, $slip['totals']['penalty_late'], 0.001);
    }

    public function test_editing_keeps_the_deduction_sign_whatever_is_typed(): void {
        $admin = User::factory()->create();
        $employee = $this->employeeWithDays(['2026-08-03' => '08:15:00']);
        $this->generate($admin);
        $row = PayrollAdjustment::where('employee_id', $employee->id)->first();

        // Typed positive, as the form does — it must still subtract.
        $this->actingAs($admin)->patchJson("/api/admin/adjustments/{$row->id}", ['amount' => 50])->assertOk();
        $this->assertEqualsWithDelta(-50.00, (float) $row->fresh()->amount, 0.001);
    }

    public function test_an_on_time_period_generates_nothing(): void {
        $admin = User::factory()->create();
        $this->employeeWithDays(['2026-08-03' => '08:00:00']);

        $this->generate($admin)->assertOk()->assertJsonPath('generated', 0);
    }

    public function test_the_generator_requires_authentication(): void {
        $this->postJson('/api/admin/payroll/period/penalty-lates?month=2026-08&period=first')->assertStatus(401);
    }
}
