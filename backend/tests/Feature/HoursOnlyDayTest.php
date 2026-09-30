<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollSetting;
use App\Models\User;
use App\Services\AttendancePayCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An "hours only" day: staff called in on their rest day to unload a delivery
 * truck. The office could not enter it — the form wanted a full regular day
 * before it would take the hours, and a full day is not what was worked. The
 * hours are paid one for one at the normal hourly rate.
 */
class HoursOnlyDayTest extends TestCase {
    use RefreshDatabase;

    private function settings(): PayrollSetting {
        return new PayrollSetting([
            'daily_basic_rate' => 505.00,
            'overtime_multiplier' => 1.25,
            'night_diff_multiplier' => 0.10,
            'unpaid_break_hours' => 1.0,
        ]);
    }

    private function compute(string $in, string $out, array $day = []): array {
        return (new AttendancePayCalculator())
            ->compute($in, $out, $this->settings(), null, '08:00', '17:00', $day + ['absence_type' => 'hours_only']);
    }

    public function test_the_hours_are_paid_at_the_straight_hourly_rate(): void {
        $pay = $this->compute('13:00', '16:00');

        // 3 h x 63.125, and nothing else on the day.
        $this->assertSame(189.38, $pay['basic']);
        $this->assertSame(189.38, $pay['total']);
        $this->assertSame(3.0, $pay['regular_hours']);
        $this->assertSame(0.0, $pay['ot']);
        $this->assertSame('Hours Only', $pay['premium_label']);
    }

    public function test_arriving_long_after_the_usual_shift_start_is_not_late(): void {
        $pay = $this->compute('13:00', '16:00');

        $this->assertFalse($pay['late']);
        $this->assertSame(0.0, $pay['tardiness']);
        $this->assertSame(0.0, $pay['undertime']);
    }

    public function test_hours_past_the_usual_shift_end_are_not_overtime(): void {
        $pay = $this->compute('16:00', '20:00');

        $this->assertSame(0.0, $pay['ot_hours']);
        $this->assertSame(252.50, $pay['total']); // 4 h x 63.125
    }

    public function test_no_break_is_taken_out_unless_one_was_stamped(): void {
        $this->assertSame(6.0, $this->compute('08:00', '14:00')['regular_hours']);
        $this->assertSame(5.0, $this->compute('08:00', '14:00', ['break_out' => '11:00', 'break_in' => '12:00'])['regular_hours']);
    }

    public function test_the_rest_day_premium_never_applies(): void {
        // Management's instruction: no 130% on these hours, flagged or not.
        $pay = $this->compute('13:00', '16:00', ['is_rest_day' => true]);

        $this->assertSame(189.38, $pay['basic']);
        $this->assertSame(1.0, $pay['premium_multiplier']);
        $this->assertSame('Hours Only', $pay['premium_label']);
    }

    public function test_a_manual_hours_only_entry_reaches_the_payslip(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => 505]);

        $this->actingAs($admin)->postJson("/api/admin/employees/{$employee->id}/attendance/manual", [
            'work_date' => '2026-08-03', 'clock_in' => '13:00', 'clock_out' => '17:00',
            'reason' => 'Other', 'absence_type' => 'hours_only',
        ])->assertCreated();

        $slip = $this->actingAs($admin)
            ->getJson("/api/admin/employees/{$employee->id}/payslip?month=2026-08&period=first")
            ->assertOk()->json();

        $this->assertEqualsWithDelta(252.50, $slip['totals']['basic'], 0.01);
        $this->assertEqualsWithDelta(0.0, $slip['totals']['ot'], 0.01);
        $this->assertEqualsWithDelta(0.0, $slip['totals']['tardiness'], 0.01);
        // Four hours is half a day, so Basic Wage still reads as days x rate.
        $this->assertEqualsWithDelta(0.5, $slip['slip']['days_worked'], 0.01);
    }

    public function test_an_existing_rest_day_can_be_turned_into_an_hours_only_day(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();
        $record = AttendanceRecord::factory()->for($employee)->create([
            'clock_in' => '08:00:00', 'clock_out' => '17:00:00', 'absence_type' => 'rest_day',
        ]);

        $this->actingAs($admin)->patchJson("/api/admin/attendance/{$record->id}/adjust", [
            'clock_in' => '14:00', 'clock_out' => '16:30', 'reason' => 'Other',
            'absence_type' => 'hours_only', 'ot_in' => null, 'ot_out' => null,
        ])->assertOk();

        $this->assertSame('hours_only', $record->fresh()->absence_type);
    }

    public function test_an_overtime_pair_is_refused_on_an_hours_only_day(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();

        $this->actingAs($admin)->postJson("/api/admin/employees/{$employee->id}/attendance/manual", [
            'work_date' => '2026-08-03', 'clock_in' => '13:00', 'clock_out' => '17:00',
            'reason' => 'Other', 'absence_type' => 'hours_only', 'ot_in' => '18:00', 'ot_out' => '20:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['ot_in', 'ot_out']);
    }
}
