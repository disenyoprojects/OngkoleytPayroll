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

    public function test_break_is_deducted_from_worked_hours_chimichanga_example(): void {
        $calc = new AttendancePayCalculator();

        // 12:50 PM -> 10:23 PM = 9h33m span; less 1h break = 8h33m (8.55h) worked.
        $pay = $calc->compute('12:50', '22:23', $this->settings(1.0));

        $this->assertSame(8.0, $pay['regular_hours']);
        $this->assertEqualsWithDelta(0.55, $pay['ot_hours'], 0.001);
        $this->assertEqualsWithDelta(8.55, $pay['total_hours'], 0.001);

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

        // Same shift, but break disabled: full 9.55h worked -> 8 regular + 1.55 OT.
        $pay = $calc->compute('12:50', '22:23', $this->settings(0.0));

        $this->assertSame(8.0, $pay['regular_hours']);
        $this->assertEqualsWithDelta(1.55, $pay['ot_hours'], 0.001);
    }

    public function test_break_not_deducted_when_shift_is_shorter_than_the_break(): void {
        $calc = new AttendancePayCalculator();

        // 08:00 -> 08:30 = 0.5h span, shorter than the 1h break: nothing deducted.
        $pay = $calc->compute('08:00', '08:30', $this->settings(1.0));

        $this->assertEqualsWithDelta(0.5, $pay['total_hours'], 0.001);
        $this->assertEqualsWithDelta(0.5, $pay['regular_hours'], 0.001);
        $this->assertSame(0.0, $pay['ot_hours']);
    }
}
