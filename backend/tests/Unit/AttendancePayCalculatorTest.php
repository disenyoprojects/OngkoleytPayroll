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

    public function test_handles_a_shift_that_crosses_midnight(): void {
        // Night shift 22:00-06:00. Clock 22:00-02:00 = 4h in-shift; less the 1h
        // unpaid break = 3h regular, no OT (before shift_end).
        $result = (new AttendancePayCalculator())->compute('22:00', '02:00', $this->settings(), null, '22:00', '06:00');

        $this->assertSame(3.0, $result['total_hours']);
    }
}
