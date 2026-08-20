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

    public function test_no_tardiness_when_on_time(): void {
        $result = (new AttendancePayCalculator())->compute('08:00', '17:00', $this->settings(), null, '08:00', '17:00');

        $this->assertFalse($result['late']);
        $this->assertSame(0.0, $result['tardiness']);
    }

    public function test_tardiness_is_the_peso_value_of_the_late_minutes(): void {
        // Clock in 08:15 for an 08:00 shift → 15 min late. The basic wage still
        // covers the full scheduled shift; the 15 min comes off as Tardiness.
        $result = (new AttendancePayCalculator())->compute('08:15', '17:00', $this->settings(), null, '08:00', '17:00');
        $hourlyRate = 505 / 8;

        $this->assertTrue($result['late']);
        $this->assertSame(15, $result['late_minutes']);
        $this->assertEqualsWithDelta(round(0.25 * $hourlyRate, 2), $result['tardiness'], 0.01);
        // Basic is the on-time amount (8h after the 1h break), not hour-docked.
        $this->assertEqualsWithDelta(round($hourlyRate * 8, 2), $result['basic'], 0.01);
        // No OT or night diff here, so total = basic − tardiness.
        $this->assertEqualsWithDelta($result['basic'] - $result['tardiness'], $result['total'], 0.01);
    }

    public function test_no_flat_penalty_is_applied_to_a_late_day(): void {
        // The ₱75 late penalty is entered as an Authorized Deduction adjustment
        // now — the calculator must not deduct it on its own.
        $settings = $this->settings();
        $settings->late_penalty_amount = 75;
        $result = (new AttendancePayCalculator())->compute('08:15', '17:00', $settings, null, '08:00', '17:00');

        $this->assertEqualsWithDelta($result['basic'] - $result['tardiness'], $result['total'], 0.01);
    }

    public function test_undertime_is_charged_for_an_early_clock_out(): void {
        // Out at 16:00 on a 17:00 shift → 1h short. Basic still covers the full
        // shift; the hour comes off under Undertime, so net is unchanged.
        $result = (new AttendancePayCalculator())->compute('08:00', '16:00', $this->settings(), null, '08:00', '17:00');
        $hourlyRate = 505 / 8;

        $this->assertSame(60, $result['undertime_minutes']);
        $this->assertEqualsWithDelta(round($hourlyRate, 2), $result['undertime'], 0.01);
        $this->assertEqualsWithDelta(round($hourlyRate * 8, 2), $result['basic'], 0.01);
        $this->assertEqualsWithDelta($result['basic'] - $result['undertime'], $result['total'], 0.01);
        // Hours reported are the hours actually stood, not the grossed-up ones.
        $this->assertEqualsWithDelta(7.0, $result['total_hours'], 0.01);
    }

    public function test_overbreak_beyond_the_allowance_is_charged_as_undertime(): void {
        // 1h unpaid break is standard; a 12:00–13:30 break is 30 min over.
        $result = (new AttendancePayCalculator())->compute(
            '08:00', '17:00', $this->settings(), null, '08:00', '17:00',
            ['break_out' => '12:00', 'break_in' => '13:30'],
        );
        $hourlyRate = 505 / 8;

        $this->assertEqualsWithDelta(0.5, $result['overbreak_hours'], 0.001);
        $this->assertEqualsWithDelta(round(0.5 * $hourlyRate, 2), $result['undertime'], 0.01);
        $this->assertEqualsWithDelta(7.5, $result['total_hours'], 0.01);
    }

    public function test_a_shorter_than_standard_break_earns_nothing_extra(): void {
        // Back from break after 30 min instead of the standard hour.
        $result = (new AttendancePayCalculator())->compute(
            '08:00', '17:00', $this->settings(), null, '08:00', '17:00',
            ['break_out' => '12:00', 'break_in' => '12:30'],
        );
        $hourlyRate = 505 / 8;

        // Nothing is charged back — but the half hour not taken is not paid
        // either. The day is worth its scheduled hours, no more.
        $this->assertSame(0.0, $result['undertime']);
        $this->assertEqualsWithDelta(8.0, $result['total_hours'], 0.01);
        $this->assertEqualsWithDelta(round($hourlyRate * 8, 2), $result['basic'], 0.01);
    }

    public function test_the_day_pays_the_flat_daily_rate_when_the_shift_is_stood_in_full(): void {
        // An 11:00–20:00 shift is 9h clock-to-clock, less the 1h break = 8h,
        // which is exactly the daily rate however long the break actually ran.
        foreach ([['12:00', '13:00'], ['12:00', '12:48'], []] as $break) {
            $day = $break === [] ? [] : ['break_out' => $break[0], 'break_in' => $break[1]];
            $result = (new AttendancePayCalculator())->compute(
                '11:00', '20:00', $this->settings(), null, '11:00', '20:00', $day,
            );

            $this->assertEqualsWithDelta(505.00, $result['total'], 0.01);
        }
    }

    public function test_night_differential_covers_the_hours_before_6am(): void {
        // A 01:00–09:00 shift earns night differential from 01:00 to 06:00.
        // Measuring only forward from 22:00 used to miss these hours entirely.
        $result = (new AttendancePayCalculator())->compute('01:00', '09:00', $this->settings(), null, '01:00', '09:00');

        $this->assertEqualsWithDelta(5.0, $result['night_diff_hours'], 0.01);
    }

    public function test_night_differential_spans_across_midnight(): void {
        // 20:00–05:00 shift: the 22:00–05:00 stretch is night differential.
        $result = (new AttendancePayCalculator())->compute('20:00', '05:00', $this->settings(), null, '20:00', '05:00');

        $this->assertEqualsWithDelta(7.0, $result['night_diff_hours'], 0.01);
    }

    public function test_handles_a_shift_that_crosses_midnight(): void {
        // Night shift 22:00-06:00. Clock 22:00-02:00 = 4h in-shift; less the 1h
        // unpaid break = 3h regular, no OT (before shift_end).
        $result = (new AttendancePayCalculator())->compute('22:00', '02:00', $this->settings(), null, '22:00', '06:00');

        $this->assertSame(3.0, $result['total_hours']);
    }
}
