<?php

namespace Tests\Unit;

use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The second clock pair: staff clock out at the end of the shift, wait for the
 * delivery truck, then clock back in to unload. The wait must not be paid.
 */
class OvertimeClockPairTest extends TestCase {
    use RefreshDatabase;

    private function settings(): PayrollSetting {
        return PayrollSetting::current(); // 505/day, OT 1.25, night diff 0.10
    }

    /** August 4 on the timesheet: 08:54–12:07, 13:05–19:25, OT 19:33–20:44, 9–6 shift. */
    public function test_the_wait_between_the_shift_and_the_ot_pair_is_not_paid(): void {
        $result = (new AttendancePayCalculator())->compute(
            '08:54', '19:25', $this->settings(), null, '09:00', '18:00',
            ['break_out' => '12:07', 'break_in' => '13:05', 'ot_in' => '19:33', 'ot_out' => '20:44'],
        );

        // 18:00–19:25 = 1.4167h past the shift, plus the 1h11m OT pair = 2.5999h.
        // The 19:25–19:33 wait is in neither.
        $this->assertEqualsWithDelta(1.4167 + (71 / 60), $result['ot_hours'], 0.01);
    }

    public function test_the_ot_pair_pays_at_the_overtime_rate(): void {
        // A two-hour pair, nothing else past the shift end.
        $result = (new AttendancePayCalculator())->compute(
            '08:00', '17:00', $this->settings(), null, '08:00', '17:00',
            ['ot_in' => '19:00', 'ot_out' => '21:00'],
        );
        $hourlyRate = 505 / 8;

        $this->assertEqualsWithDelta(2.0, $result['ot_hours'], 0.01);
        $this->assertEqualsWithDelta(round(2 * $hourlyRate * 1.25, 2), $result['ot'], 0.01);
    }

    public function test_night_differential_covers_only_the_hours_past_10pm(): void {
        // OT 20:00–23:00: three hours of overtime, but only 22:00–23:00 is
        // night differential — exactly the one hour the client described.
        $result = (new AttendancePayCalculator())->compute(
            '08:00', '17:00', $this->settings(), null, '08:00', '17:00',
            ['ot_in' => '20:00', 'ot_out' => '23:00'],
        );
        $hourlyRate = 505 / 8;

        $this->assertEqualsWithDelta(3.0, $result['ot_hours'], 0.01);
        $this->assertEqualsWithDelta(1.0, $result['night_diff_hours'], 0.01);
        $this->assertEqualsWithDelta(round($hourlyRate * 0.10, 2), $result['night_diff'], 0.01);
    }

    public function test_ot_that_runs_past_midnight_is_counted_whole(): void {
        // Unloading from 22:30 to 00:30 — two hours of OT, all of it night work.
        $result = (new AttendancePayCalculator())->compute(
            '08:00', '17:00', $this->settings(), null, '08:00', '17:00',
            ['ot_in' => '22:30', 'ot_out' => '00:30'],
        );

        $this->assertEqualsWithDelta(2.0, $result['ot_hours'], 0.01);
        $this->assertEqualsWithDelta(2.0, $result['night_diff_hours'], 0.01);
    }

    public function test_an_unfinished_ot_pair_pays_nothing_extra(): void {
        // Clocked in for OT but never out — nothing to measure, so no OT.
        $result = (new AttendancePayCalculator())->compute(
            '08:00', '17:00', $this->settings(), null, '08:00', '17:00',
            ['ot_in' => '19:00'],
        );

        $this->assertSame(0.0, $result['ot_hours']);
    }
}
