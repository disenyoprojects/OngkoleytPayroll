<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End to end: the four punches on the Clock In/Out screen, then the payslip
 * they produce. Proves the OT pair reaches the money, not just the database.
 */
class OvertimePunchToPayslipTest extends TestCase {
    use RefreshDatabase;

    /**
     * A punch is rejected if it is more than five minutes ahead of the server
     * clock, so the whole day has to be in the past — freeze "now" at the end
     * of a fixed evening rather than letting the wall clock decide.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-08-10 23:59:00'));
    }

    private function punch(User $admin, Employee $employee, string $action, string $at) {
        return $this->actingAs($admin)->postJson("/api/admin/clock/{$action}", [
            'employee_id' => $employee->id,
            'clocked_at' => now()->setTimeFromTimeString($at)->toIso8601String(),
        ]);
    }

    public function test_a_delivery_night_reaches_the_payslip(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create([
            'daily_basic_rate' => 505, 'shift_start' => '09:00:00', 'shift_end' => '18:00:00',
        ]);

        // 09:00-18:00 shift worked exactly, then a delivery unloaded 20:00-23:00.
        $this->punch($admin, $employee, 'in', '09:00')->assertOk();
        $this->punch($admin, $employee, 'out', '18:00')->assertOk();
        $this->punch($admin, $employee, 'ot-in', '20:00')->assertOk();
        $this->punch($admin, $employee, 'ot-out', '23:00')->assertOk();

        $month = '2026-08';
        $period = 'first';
        $slip = $this->actingAs($admin)
            ->getJson("/api/admin/employees/{$employee->id}/payslip?month={$month}&period={$period}")
            ->assertOk()->json();

        $hourly = 505 / 8;

        // 8h paid regular (9h shift less the 1h break) => 505.00 basic.
        $this->assertEqualsWithDelta(505.00, $slip['totals']['basic'], 0.01);

        // The 18:00-20:00 wait for the truck earns nothing; only the 3h pair.
        $this->assertEqualsWithDelta(3.0, $slip['lines'][0]['hours'] - 8.0, 0.01);
        $this->assertEqualsWithDelta(round(3 * $hourly * 1.25, 2), $slip['totals']['ot'], 0.01);

        // Night differential is the 22:00-23:00 hour only, at 10% of hourly.
        $this->assertEqualsWithDelta(round($hourly * 0.10, 2), $slip['totals']['night_diff'], 0.01);

        // Nothing charged back — on time, and out exactly at the shift end.
        $this->assertEqualsWithDelta(0.0, $slip['totals']['tardiness'], 0.01);
        $this->assertEqualsWithDelta(0.0, $slip['totals']['undertime'], 0.01);

        $expectedGross = round(505.00 + round(3 * $hourly * 1.25, 2) + round($hourly * 0.10, 2), 2);
        $this->assertEqualsWithDelta($expectedGross, $slip['totals']['gross'], 0.01);
    }

    public function test_without_the_ot_pair_the_same_day_pays_only_the_shift(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create([
            'daily_basic_rate' => 505, 'shift_start' => '09:00:00', 'shift_end' => '18:00:00',
        ]);

        $this->punch($admin, $employee, 'in', '09:00')->assertOk();
        $this->punch($admin, $employee, 'out', '18:00')->assertOk();

        $month = '2026-08';
        $period = 'first';
        $slip = $this->actingAs($admin)
            ->getJson("/api/admin/employees/{$employee->id}/payslip?month={$month}&period={$period}")
            ->assertOk()->json();

        $this->assertEqualsWithDelta(0.0, $slip['totals']['ot'], 0.01);
        $this->assertEqualsWithDelta(0.0, $slip['totals']['night_diff'], 0.01);
        $this->assertEqualsWithDelta(505.00, $slip['totals']['gross'], 0.01);
    }
}
