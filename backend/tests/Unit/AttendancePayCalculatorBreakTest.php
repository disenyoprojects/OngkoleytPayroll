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

        // Same shift and clock with the break disabled. Regular hours are what
        // the daily rate buys — eight — not the scheduled span, so turning the
        // break off no longer lengthens the paid day; it only stops an hour
        // being taken out of the middle of it.
        $pay = $calc->compute('12:50', '22:23', $this->settings(0.0), null, '12:50', '21:50');

        $this->assertSame(8.0, $pay['regular_hours']);
        $this->assertEqualsWithDelta(0.55, $pay['ot_hours'], 0.001);
    }

    /**
     * Not everyone is scheduled nine hours. Kath: "pag ang shift po nila ay
     * 1 to 8 or 12 to 7 straight with no break... kaya po nagiging 9 hours ang
     * regular shift to accommodate 1 hour break."
     *
     * So the nine-hour shift is nine only to fit the break around it, and a
     * straight shift is the same day's work. Both pay the daily rate flat.
     */
    public function test_a_straight_shift_with_no_break_pays_the_same_daily_rate(): void {
        $calc = new AttendancePayCalculator();

        foreach ([['13:00', '20:00'], ['12:00', '19:00'], ['11:00', '20:00']] as [$from, $to]) {
            $pay = $calc->compute($from, $to, $this->settings(1.0), null, $from, $to);

            $this->assertSame(8.0, $pay['regular_hours'], "{$from}-{$to}");
            $this->assertSame(505.00, round($pay['total'], 2), "{$from}-{$to}");
        }
    }

    /** On a straight shift, overtime still starts at the scheduled end. */
    public function test_overtime_on_a_straight_shift_starts_at_the_scheduled_end(): void {
        $calc = new AttendancePayCalculator();

        // 13:00-20:00 scheduled, out at 20:30 -> 0.5h OT at 63.125 x 1.25.
        $pay = $calc->compute('13:00', '20:30', $this->settings(1.0), null, '13:00', '20:00');

        $this->assertEqualsWithDelta(0.5, $pay['ot_hours'], 0.001);
        $this->assertSame(39.45, round($pay['ot'], 2));
        $this->assertSame(544.45, round($pay['total'], 2));
    }
}
