<?php

namespace Tests\Unit;

use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendancePayCalculatorTest extends TestCase {
    use RefreshDatabase;

    private function settings(): PayrollSetting {
        return PayrollSetting::current(); // daily_basic_rate 505, OT 1.25, night diff 0.10
    }

    public function test_returns_null_when_clock_out_is_missing(): void {
        $result = (new AttendancePayCalculator())->compute('08:00', null, $this->settings());

        $this->assertNull($result);
    }

    public function test_computes_a_standard_eight_hour_shift(): void {
        // 08:00-17:00 is a 9h span; less the 1h unpaid break = 8h worked, no OT.
        $result = (new AttendancePayCalculator())->compute('08:00', '17:00', $this->settings());

        $this->assertSame(8.0, $result['total_hours']);
        $this->assertSame(8.0, $result['regular_hours']);
        $this->assertSame(0.0, $result['ot_hours']);
        $this->assertIsFloat($result['night_diff_hours']);
        $hourlyRate = 505 / 8;
        $this->assertEqualsWithDelta(round($hourlyRate * 8, 2), $result['basic'], 0.01);
        $this->assertSame(0.0, $result['ot']);
    }

    public function test_computes_night_differential_for_hours_between_10pm_and_6am(): void {
        $result = (new AttendancePayCalculator())->compute('20:00', '23:00', $this->settings());

        $this->assertEqualsWithDelta(1.0, $result['night_diff_hours'], 0.01);
        $this->assertIsFloat($result['night_diff_hours']);
        $hourlyRate = 505 / 8;
        $this->assertEqualsWithDelta(round($hourlyRate * 1 * 0.10, 2), $result['night_diff'], 0.01);
    }

    public function test_no_late_penalty_when_on_time(): void {
        $result = (new AttendancePayCalculator())->compute('08:00', '17:00', $this->settings(), null, '08:00', '17:00');

        $this->assertFalse($result['late']);
        $this->assertSame(0.0, $result['late_penalty']);
    }

    public function test_flat_late_penalty_is_deducted_from_total(): void {
        // Clock in 08:15 for an 08:00 shift → late by any margin → flat ₱75 off.
        $result = (new AttendancePayCalculator())->compute('08:15', '17:00', $this->settings(), null, '08:00', '17:00');

        $this->assertTrue($result['late']);
        $this->assertSame(75.0, $result['late_penalty']);
        // No OT or night diff here, so total = basic − penalty.
        $this->assertEqualsWithDelta($result['basic'] - 75.0, $result['total'], 0.01);
        // The 13th-month base is unaffected by the penalty.
        $this->assertEqualsWithDelta($result['basic'], $result['base_wage'], 0.01);
    }

    public function test_handles_a_shift_that_crosses_midnight(): void {
        // Night shift 22:00-06:00. Clock 22:00-02:00 = 4h in-shift; less the 1h
        // unpaid break = 3h regular, no OT (before shift_end).
        $result = (new AttendancePayCalculator())->compute('22:00', '02:00', $this->settings(), null, '22:00', '06:00');

        $this->assertSame(3.0, $result['total_hours']);
    }
}
