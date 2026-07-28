<?php

namespace App\Services;

use App\Models\PayrollSetting;

class AttendancePayCalculator {
    private const DEFAULT_SHIFT_START = '08:00';
    private const DEFAULT_SHIFT_END = '17:00';
    private const NO_PAY_ABSENCES = ['absent', 'awol', 'travel', 'leave', 'sick_leave'];

    public function computeForRecord(\App\Models\AttendanceRecord $record, PayrollSetting $settings): ?array {
        $employee = $record->employee;
        $rate = $employee?->daily_basic_rate === null ? null : (float) $employee->daily_basic_rate;
        $shiftStart = $record->shift_start ?: ($employee?->shift_start);
        $shiftEnd = $record->shift_end ?: ($employee?->shift_end);

        return $this->compute(
            $record->clock_in,
            $record->clock_out,
            $settings,
            $rate,
            $shiftStart,
            $shiftEnd,
            [
                'holiday_type' => $record->holiday_type,
                'is_rest_day' => (bool) $record->is_rest_day,
                'absence_type' => $record->absence_type,
                'break_out' => $record->break_out,
                'break_in' => $record->break_in,
            ],
        );
    }

    public function compute(
        ?string $clockIn,
        ?string $clockOut,
        PayrollSetting $settings,
        ?float $dailyRateOverride = null,
        ?string $shiftStart = null,
        ?string $shiftEnd = null,
        array $day = [],
    ): ?array {
        if (! $clockIn || ! $clockOut) {
            return null;
        }

        $holidayType = $day['holiday_type'] ?? null;
        $isRestDay = (bool) ($day['is_rest_day'] ?? false);
        $absenceType = $day['absence_type'] ?? null;
        $premiumLabel = DoleRates::label($holidayType, $isRestDay);
        $regularMult = DoleRates::regularMultiplier($holidayType, $isRestDay);

        // No-pay absence statuses short-circuit to a zeroed result.
        if (in_array($absenceType, self::NO_PAY_ABSENCES, true)) {
            return $this->zeroed($premiumLabel, $regularMult);
        }

        $start = $this->minutesOf($clockIn);
        $end = $this->minutesOf($clockOut);
        if ($end <= $start) {
            $end += 24 * 60;
        }

        $shiftStartMin = $this->minutesOf($shiftStart ?: self::DEFAULT_SHIFT_START);
        $shiftEndMin = $this->minutesOf($shiftEnd ?: self::DEFAULT_SHIFT_END);
        if ($shiftEndMin <= $shiftStartMin) {
            $shiftEndMin += 24 * 60;
        }

        // Break: actual window if both given, else the flat setting.
        if (! empty($day['break_out']) && ! empty($day['break_in'])) {
            $breakHours = max(0, ($this->minutesOf($day['break_in']) - $this->minutesOf($day['break_out'])) / 60.0);
        } else {
            $breakHours = (float) ($settings->unpaid_break_hours ?? 0);
        }

        $regWithinMin = (float) max(0, min($end, $shiftEndMin) - max($start, $shiftStartMin));
        $regWithinHours = $regWithinMin / 60.0;
        $regularHours = $regWithinHours > $breakHours ? $regWithinHours - $breakHours : $regWithinHours;

        // Half day caps paid regular at half the scheduled (post-break) hours.
        if ($absenceType === 'half_day') {
            $scheduledHours = max(0, ($shiftEndMin - $shiftStartMin) / 60.0 - $breakHours);
            $regularHours = min($regularHours, $scheduledHours / 2.0);
        }

        $otMin = (float) max(0, $end - max($shiftEndMin, $start));
        $otHours = $otMin / 60.0;

        $dailyRate = $dailyRateOverride ?? (float) $settings->daily_basic_rate;
        $hourlyRate = $dailyRate / 8;

        $paidStart = max($start, $shiftStartMin);
        $nightStart = 22 * 60;
        $nightEnd = 24 * 60 + 6 * 60;
        $overlapStart = max($paidStart, $nightStart);
        $overlapEnd = min($end, $nightEnd);
        $nightDiffHours = (float) max(0, ($overlapEnd - $overlapStart) / 60.0);

        // Regular pay at the day's premium multiplier.
        $basic = round($regularHours * $hourlyRate * $regularMult, 2);
        // OT: ordinary is hourly*ot_multiplier; premium days are 130% of the premium hourly rate.
        $isPremiumDay = $holidayType !== null || $isRestDay;
        $otRate = $isPremiumDay
            ? $hourlyRate * $regularMult * 1.30
            : $hourlyRate * (float) $settings->overtime_multiplier;
        $ot = round($otHours * $otRate, 2);
        // Night diff: +nd_multiplier of the applicable premium hourly rate.
        $nightDiff = round($nightDiffHours * $hourlyRate * $regularMult * (float) $settings->night_diff_multiplier, 2);
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
            'premium_label' => $premiumLabel,
            'premium_multiplier' => $regularMult,
        ];
    }

    private function zeroed(string $label, float $mult): array {
        return [
            'total_hours' => 0.0, 'regular_hours' => 0.0, 'ot_hours' => 0.0, 'night_diff_hours' => 0.0,
            'basic' => 0.0, 'ot' => 0.0, 'night_diff' => 0.0, 'total' => 0.0,
            'premium_label' => $label, 'premium_multiplier' => $mult,
        ];
    }

    private function minutesOf(string $hhmm): int {
        [$h, $m] = array_map('intval', explode(':', substr($hhmm, 0, 5)));
        return $h * 60 + $m;
    }
}
