<?php

namespace App\Services;

use App\Models\PayrollSetting;

class AttendancePayCalculator {
    private const DEFAULT_SHIFT_START = '08:00';
    private const DEFAULT_SHIFT_END = '17:00';

    public function compute(
        ?string $clockIn,
        ?string $clockOut,
        PayrollSetting $settings,
        ?float $dailyRateOverride = null,
        ?string $shiftStart = null,
        ?string $shiftEnd = null,
    ): ?array {
        if (! $clockIn || ! $clockOut) {
            return null;
        }

        $start = $this->minutesOf($clockIn);
        $end = $this->minutesOf($clockOut);
        if ($end <= $start) {
            $end += 24 * 60;
        }

        $shiftStartMin = $this->minutesOf($shiftStart ?: self::DEFAULT_SHIFT_START);
        $shiftEndMin = $this->minutesOf($shiftEnd ?: self::DEFAULT_SHIFT_END);
        if ($shiftEndMin <= $shiftStartMin) {
            $shiftEndMin += 24 * 60; // overnight shift
        }

        $breakHours = (float) ($settings->unpaid_break_hours ?? 0);

        // Regular = time actually worked INSIDE the scheduled shift window,
        // less the unpaid break. Arriving late or leaving early simply yields
        // fewer paid minutes; time worked before shift_start is ignored.
        $regWithinMin = (float) max(0, min($end, $shiftEndMin) - max($start, $shiftStartMin));
        $regWithinHours = $regWithinMin / 60.0;
        $regularHours = $regWithinHours > $breakHours ? $regWithinHours - $breakHours : $regWithinHours;

        // Overtime = time worked AFTER shift_end.
        $otMin = (float) max(0, $end - max($shiftEndMin, $start));
        $otHours = $otMin / 60.0;

        $dailyRate = $dailyRateOverride ?? (float) $settings->daily_basic_rate;
        $hourlyRate = $dailyRate / 8;

        // Night differential = paid time (shift_start onward, so pre-shift is
        // excluded) that falls within 22:00–06:00.
        $paidStart = max($start, $shiftStartMin);
        $nightStart = 22 * 60;
        $nightEnd = 24 * 60 + 6 * 60;
        $overlapStart = max($paidStart, $nightStart);
        $overlapEnd = min($end, $nightEnd);
        $nightDiffHours = (float) max(0, ($overlapEnd - $overlapStart) / 60.0);

        $basic = round($regularHours * $hourlyRate, 2);
        $ot = round($otHours * $hourlyRate * (float) $settings->overtime_multiplier, 2);
        $nightDiff = round($nightDiffHours * $hourlyRate * (float) $settings->night_diff_multiplier, 2);
        $total = round($basic + $ot + $nightDiff, 2);

        return [
            'total_hours' => round($regularHours + $otHours, 4),
            'regular_hours' => $regularHours,
            'ot_hours' => $otHours,
            'night_diff_hours' => $nightDiffHours,
            'basic' => $basic,
            'ot' => $ot,
            'night_diff' => $nightDiff,
            'total' => $total,
        ];
    }

    private function minutesOf(string $hhmm): int {
        [$h, $m] = array_map('intval', explode(':', substr($hhmm, 0, 5)));
        return $h * 60 + $m;
    }
}
