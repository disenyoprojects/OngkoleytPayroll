<?php

namespace Tests\Unit;

use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use Tests\TestCase;

class DolePremiumCalculatorTest extends TestCase {
    private function settings(): PayrollSetting {
        return new PayrollSetting([
            'daily_basic_rate' => 505.00,
            'overtime_multiplier' => 1.25,
            'night_diff_multiplier' => 0.10,
            'unpaid_break_hours' => 1.0,
        ]);
    }

    // Shift 09:00-18:00 (9h window, 8h after 1h break); clock 09:00-18:00.
    private function eightHourDay(array $day): array {
        return (new AttendancePayCalculator())
            ->compute('09:00', '18:00', $this->settings(), null, '09:00', '18:00', $day);
    }

    public function test_ordinary_day_no_premium(): void {
        $pay = $this->eightHourDay([]);
        $this->assertSame(505.00, $pay['basic']);
        $this->assertSame(505.00, $pay['total']);
        $this->assertSame(1.0, $pay['premium_multiplier']);
    }

    public function test_rest_day_worked_is_130_percent(): void {
        $pay = $this->eightHourDay(['is_rest_day' => true]);
        // 8 * 63.125 * 1.30 = 656.50
        $this->assertSame(656.50, $pay['basic']);
        $this->assertSame(656.50, $pay['total']);
        $this->assertSame('Rest Day', $pay['premium_label']);
    }

    public function test_special_holiday_worked_is_130_percent(): void {
        $pay = $this->eightHourDay(['holiday_type' => 'special']);
        $this->assertSame(656.50, $pay['basic']);
        $this->assertSame('Special Holiday', $pay['premium_label']);
    }

    public function test_special_holiday_on_rest_day_is_150_percent(): void {
        $pay = $this->eightHourDay(['holiday_type' => 'special', 'is_rest_day' => true]);
        // 8 * 63.125 * 1.50 = 757.50
        $this->assertSame(757.50, $pay['basic']);
    }

    public function test_regular_holiday_worked_is_200_percent(): void {
        $pay = $this->eightHourDay(['holiday_type' => 'regular']);
        // 8 * 63.125 * 2.00 = 1010.00
        $this->assertSame(1010.00, $pay['basic']);
    }

    public function test_absent_pays_zero(): void {
        $pay = $this->eightHourDay(['absence_type' => 'absent']);
        $this->assertSame(0.0, $pay['total']);
    }

    public function test_leave_and_travel_pay_zero_by_default(): void {
        $this->assertSame(0.0, $this->eightHourDay(['absence_type' => 'leave'])['total']);
        $this->assertSame(0.0, $this->eightHourDay(['absence_type' => 'travel'])['total']);
    }

    public function test_unworked_rest_day_status_pays_zero(): void {
        // Distinct from is_rest_day=true (worked their rest day, premium pay):
        // absence_type=rest_day marks the day as their scheduled day off.
        $this->assertSame(0.0, $this->eightHourDay(['absence_type' => 'rest_day'])['total']);
    }

    public function test_half_day_caps_regular_at_half_scheduled(): void {
        // Worked the full 8h but flagged half_day: regular capped at 4h.
        $pay = $this->eightHourDay(['absence_type' => 'half_day']);
        // 4 * 63.125 = 252.50
        $this->assertSame(252.50, $pay['basic']);
    }

    public function test_overtime_on_special_holiday_is_130_percent_of_premium_hourly(): void {
        // Shift 09:00-18:00 (9h window, 8h after 1h break); clock 09:00-20:00 special holiday.
        // Regular = 8 * 63.125 * 1.30 = 656.50
        // OT = 2h (18:00-20:00) * 63.125 * 1.30 * 1.30 = 2 * 63.125 * 1.69 = 213.3625 -> 213.36
        $pay = (new AttendancePayCalculator())
            ->compute('09:00', '20:00', $this->settings(), null, '09:00', '18:00', ['holiday_type' => 'special']);
        $this->assertSame(656.50, $pay['basic']);
        $this->assertSame(213.36, $pay['ot']);
    }

    public function test_night_diff_stacks_on_special_holiday_rate(): void {
        // Shift 14:00-23:00 (9h window, 8h after break); clock 14:00-23:00 special holiday.
        // Night hours 22:00-23:00 = 1h. Night pay = 1 * 63.125 * 1.30 * 0.10 = 8.21.
        $pay = (new AttendancePayCalculator())
            ->compute('14:00', '23:00', $this->settings(), null, '14:00', '23:00', ['holiday_type' => 'special']);
        $this->assertSame(8.21, $pay['night_diff']);
    }
}
