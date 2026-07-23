<?php

namespace App\Services;

use App\Models\PayrollSetting;

class AttendancePayCalculator {
    public function compute(?string $clockIn, ?string $clockOut, PayrollSetting $settings, ?float $dailyRateOverride = null): ?array {
        if (! $clockIn || ! $clockOut) {
            return null;
        }

        $start = $this->minutesOf($clockIn);
        $end = $this->minutesOf($clockOut);
        if ($end <= $start) {
            $end += 24 * 60;
        }

        $totalHours = ($end - $start) / 60.0;
        $dailyRate = $dailyRateOverride ?? (float) $settings->daily_basic_rate;
        $hourlyRate = $dailyRate / 8;
        $regularHours = (float) min($totalHours, 8);
        $otHours = (float) max(0, $totalHours - 8);

        $nightStart = 22 * 60;
        $nightEnd = 24 * 60 + 6 * 60;
        $overlapStart = max($start, $nightStart);
        $overlapEnd = min($end, $nightEnd);
        $nightDiffHours = (float) max(0, ($overlapEnd - $overlapStart) / 60.0);

        $basic = round($regularHours * $hourlyRate, 2);
        $ot = round($otHours * $hourlyRate * (float) $settings->overtime_multiplier, 2);
        $nightDiff = round($nightDiffHours * $hourlyRate * (float) $settings->night_diff_multiplier, 2);
        $total = round($basic + $ot + $nightDiff, 2);

        return [
            'total_hours' => $totalHours,
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
