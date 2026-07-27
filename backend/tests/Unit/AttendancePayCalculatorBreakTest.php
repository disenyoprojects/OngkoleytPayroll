<?php

namespace Tests\Unit;

use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use Tests\TestCase;

class AttendancePayCalculatorBreakTest extends TestCase {
    private function settings(float $break = 1.0): PayrollSetting {
        return new PayrollSetting([
            'daily_basic_rate' => 505.00,
            'overtime_multiplier' => 1.25,
            'night_diff_multiplier' => 0.10,
            'unpaid_break_hours' => $break,
        ]);
    }

    public function test_shift_based_break_and_ot_chimichanga_example(): void {
        $calc = new AttendancePayCalculator();

        // Shift 12:50 PM - 9:50 PM (9h window, 8h after the 1h break).
        // Clock 12:50 PM - 10:23 PM -> 8h regular + 33m (0.55h) OT past shift end.
        $pay = $calc->compute('12:50', '22:23', $this->settings(1.0), null, '12:50', '21:50');

        $this->assertSame(8.0, $pay['regular_hours']);
        $this->assertEqualsWithDelta(0.55, $pay['ot_hours'], 0.001);

        // Basic: 8 * (505/8) = 505.00
        $this->assertSame(505.00, $pay['basic']);
        // OT: 0.55 * 63.125 * 1.25 = 43.40
        $this->assertSame(43.40, $pay['ot']);
        // Night diff: 22:00-22:23 = 0.3833h * 63.125 * 0.10 = 2.42
        $this->assertSame(2.42, $pay['night_diff']);
        // Total = 505.00 + 43.40 + 2.42 = 550.82
        $this->assertSame(550.82, $pay['total']);
    }

    public function test_no_break_deducted_when_break_hours_is_zero(): void {
        $calc = new AttendancePayCalculator();

        // Same shift/clock but break disabled: full 9h in-shift is regular.
        $pay = $calc->compute('12:50', '22:23', $this->settings(0.0), null, '12:50', '21:50');

        $this->assertSame(9.0, $pay['regular_hours']);
        $this->assertEqualsWithDelta(0.55, $pay['ot_hours'], 0.001);
    }
}
