<?php

namespace App\Services;

use App\Models\PayrollSetting;

class AttendancePayCalculator {
    private const DEFAULT_SHIFT_START = '08:00';
    private const DEFAULT_SHIFT_END = '17:00';
    public const NO_PAY_ABSENCES = ['absent', 'awol', 'travel', 'leave', 'sick_leave', 'rest_day'];

    /**
     * Prior-day statuses that forfeit the regular-holiday premium under the
     * company rule below. Deliberately only the two unpaid-absence codes: a
     * rest day, a leave, or a travel day leaves the premium intact.
     */
    public const HOLIDAY_FORFEITING_ABSENCES = ['absent', 'awol'];

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
                'ot_in' => $record->ot_in,
                'ot_out' => $record->ot_out,
                'holiday_forfeited' => $this->holidayForfeitedFor($record),
            ],
        );
    }

    /**
     * COMPANY POLICY, NOT DOLE. Management directed that an unpaid absence on
     * the calendar day immediately before a regular holiday forfeits that
     * holiday's premium even when the employee reported for work.
     *
     * The statutory rule is the opposite: both the DOLE Handbook on Workers'
     * Statutory Monetary Benefits (2024), p.18 §E.1, and the Supreme Court in
     * Nippon Paint Philippines, Inc. v. NIPPEA (G.R. No. 229396, 30 June 2021)
     * withhold holiday pay only "if he/she has not worked on such regular
     * holiday" — an employee who renders service is paid 200% regardless of the
     * preceding day. Set on management's written instruction; the deviation is
     * surfaced on the payslip through the premium label rather than hidden in
     * the multiplier, so it stays visible and is reversible from this one spot.
     */
    private function holidayForfeitedFor(\App\Models\AttendanceRecord $record): bool {
        if ($record->holiday_type !== 'regular' || ! $record->work_date) {
            return false; // Only regular holidays carry the forfeitable premium.
        }

        $previous = \App\Models\AttendanceRecord::where('employee_id', $record->employee_id)
            ->whereDate('work_date', $record->work_date->copy()->subDay())
            ->first();

        return $previous !== null
            && in_array($previous->absence_type, self::HOLIDAY_FORFEITING_ABSENCES, true);
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

        // Company policy (see holidayForfeitedFor): the regular-holiday premium
        // is dropped, so the day rates as an ordinary one. Any rest-day premium
        // survives — that is a separate benefit under Art. 93 and is not what
        // management asked to withhold.
        $holidayForfeited = $holidayType === 'regular' && (bool) ($day['holiday_forfeited'] ?? false);
        $paidHolidayType = $holidayForfeited ? null : $holidayType;

        $premiumLabel = DoleRates::label($paidHolidayType, $isRestDay);
        $regularMult = DoleRates::regularMultiplier($paidHolidayType, $isRestDay);
        // Deliberately avoids the words "Regular Holiday": PayslipController
        // buckets the premium uplift by matching that phrase first, which would
        // file a forfeited rest day's 30% under the Regular Holiday earnings line.
        if ($holidayForfeited) {
            $premiumLabel = $premiumLabel === 'Ordinary'
                ? 'Holiday Premium Forfeited'
                : $premiumLabel . ' + Holiday Premium Forfeited';
        }

        // No-pay absence statuses short-circuit to a zeroed result.
        if (in_array($absenceType, self::NO_PAY_ABSENCES, true)) {
            return $this->zeroed($premiumLabel, $regularMult, $holidayForfeited);
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

        // Break: actual window if both given, else the flat setting. The full
        // standard break always comes out of the day, whether or not it was
        // taken — a day is worth its scheduled hours and coming back early
        // does not earn extra. A longer break is charged back as Overbreak.
        $standardBreakHours = (float) ($settings->unpaid_break_hours ?? 0);
        if (! empty($day['break_out']) && ! empty($day['break_in'])) {
            $actualBreakHours = max(0, ($this->minutesOf($day['break_in']) - $this->minutesOf($day['break_out'])) / 60.0);
        } else {
            $actualBreakHours = $standardBreakHours;
        }
        $paidBreakHours = $standardBreakHours;
        $overbreakHours = max(0.0, $actualBreakHours - $standardBreakHours);

        // Regular hours are the SCHEDULED shift, not the hours actually stood:
        // the day is paid as if worked in full and the time missed at either
        // end is charged back under Tardiness and Undertime, so the payslip
        // itemises the loss instead of silently shrinking the basic wage. Net
        // pay is identical either way.
        $scheduledMin = (float) max(0, $shiftEndMin - $shiftStartMin);
        $regularHours = max(0.0, $scheduledMin / 60.0 - $paidBreakHours);

        $lateMin = (float) min(max(0, $start - $shiftStartMin), $scheduledMin);
        $earlyMin = (float) min(max(0, $shiftEndMin - max($end, $shiftStartMin)), $scheduledMin - $lateMin);

        // Half day is an agreed short day, not undertime: pay half the
        // scheduled hours and don't charge the rest back.
        if ($absenceType === 'half_day') {
            $regularHours = min($regularHours, ($scheduledMin / 60.0 - $paidBreakHours) / 2.0);
            $earlyMin = 0.0;
        }

        $otMin = (float) max(0, $end - max($shiftEndMin, $start));

        // Second clock pair for overtime: staff clock out at the end of the
        // shift, wait for the delivery truck, then clock back in to unload.
        // The wait in between is not paid, and every minute of the pair is
        // overtime whatever the clock says.
        $otPairMin = 0.0;
        if (! empty($day['ot_in']) && ! empty($day['ot_out'])) {
            $otStart = $this->minutesOf($day['ot_in']);
            $otEnd = $this->minutesOf($day['ot_out']);
            if ($otEnd <= $otStart) {
                $otEnd += 24 * 60; // unloading ran past midnight
            }
            $otPairMin = (float) ($otEnd - $otStart);
        }

        // A few minutes past the shift end is not overtime. The minimum is
        // judged on the day's overtime as a whole and the day is dropped
        // outright when it falls short — three minutes on each of ten days
        // never accumulates into a payable half hour.
        $minimumOtMin = (float) ($settings->minimum_overtime_minutes ?? 0);
        if ($otMin + $otPairMin < $minimumOtMin) {
            $otMin = 0.0;
            $otPairMin = 0.0;
        }

        $otHours = ($otMin + $otPairMin) / 60.0;

        $dailyRate = $dailyRateOverride ?? (float) $settings->daily_basic_rate;
        $hourlyRate = $dailyRate / 8;

        // Night differential is the 22:00–06:00 window only, over both the main
        // span and the overtime pair — OT ending at 23:00 earns one hour of it.
        $nightDiffHours = $this->nightHours(max($start, $shiftStartMin), $end);
        if ($otPairMin > 0) {
            $nightDiffHours += $this->nightHours($otStart, $otEnd);
        }

        // Regular pay at the day's premium multiplier.
        $basic = round($regularHours * $hourlyRate * $regularMult, 2);
        // OT: ordinary is hourly*ot_multiplier; premium days are 130% of the premium hourly rate.
        $isPremiumDay = $paidHolidayType !== null || $isRestDay;
        $otRate = $isPremiumDay
            ? $hourlyRate * $regularMult * 1.30
            : $hourlyRate * (float) $settings->overtime_multiplier;
        $ot = round($otHours * $otRate, 2);
        // Night diff: +nd_multiplier of the applicable premium hourly rate.
        $nightDiff = round($nightDiffHours * $hourlyRate * $regularMult * (float) $settings->night_diff_multiplier, 2);

        // Time charged back, all at the same rate the regular hours were paid
        // at so each exactly cancels the time not worked. Any flat penalty for
        // being late is NOT computed here — it is entered as an Authorized
        // Deduction adjustment so it shows as its own line on the payslip.
        $chargeRate = $hourlyRate * $regularMult;
        $isLate = $lateMin > 0;
        $lateMinutes = (int) round($lateMin);
        $undertimeMinutes = (int) round($earlyMin);
        $tardiness = round($lateMin / 60.0 * $chargeRate, 2);
        $undertime = round(($earlyMin / 60.0 + $overbreakHours) * $chargeRate, 2);

        $total = round($basic + $ot + $nightDiff - $tardiness - $undertime, 2);
        $workedHours = max(0.0, $regularHours - $lateMin / 60.0 - $earlyMin / 60.0 - $overbreakHours);

        // Un-premiumed base figures for the 13th-month base (PD 851): basic
        // salary only — the ordinary-rate wage, excluding holiday/rest premiums,
        // OT premium stacking, and night differential.
        $baseWage = round($regularHours * $hourlyRate, 2);
        $baseOt = round($otHours * $hourlyRate * (float) $settings->overtime_multiplier, 2);

        return [
            // Hours actually stood, for the attendance columns — the paid
            // regular hours are grossed up to the schedule, so the time charged
            // back as tardiness/undertime/overbreak comes off again here.
            'total_hours' => round($workedHours + $otHours, 4),
            'regular_hours' => $regularHours,
            'ot_hours' => $otHours,
            'night_diff_hours' => $nightDiffHours,
            'basic' => $basic,
            'ot' => $ot,
            'night_diff' => $nightDiff,
            'base_wage' => $baseWage,
            'base_ot' => $baseOt,
            'late' => $isLate,
            'late_minutes' => $lateMinutes,
            'tardiness' => $tardiness,
            'undertime_minutes' => $undertimeMinutes,
            'overbreak_hours' => round($overbreakHours, 4),
            'undertime' => $undertime,
            'total' => $total,
            'premium_label' => $premiumLabel,
            'premium_multiplier' => $regularMult,
            'holiday_forfeited' => $holidayForfeited,
        ];
    }

    private function zeroed(string $label, float $mult, bool $holidayForfeited = false): array {
        return [
            'total_hours' => 0.0, 'regular_hours' => 0.0, 'ot_hours' => 0.0, 'night_diff_hours' => 0.0,
            'basic' => 0.0, 'ot' => 0.0, 'night_diff' => 0.0,
            'base_wage' => 0.0, 'base_ot' => 0.0,
            'late' => false, 'late_minutes' => 0, 'tardiness' => 0.0,
            'undertime_minutes' => 0, 'overbreak_hours' => 0.0, 'undertime' => 0.0, 'total' => 0.0,
            'premium_label' => $label, 'premium_multiplier' => $mult,
            'holiday_forfeited' => $holidayForfeited,
        ];
    }

    /**
     * Paid hours falling inside the 22:00–06:00 night differential window.
     * The window is checked against every calendar day the paid span can
     * touch, so the 00:00–06:00 half is still counted for a shift that begins
     * after midnight — measuring only forward from 22:00 missed those hours.
     */
    private function nightHours(float $from, float $to): float {
        $hours = 0.0;

        foreach ([-1440, 0, 1440] as $dayOffset) {
            $windowStart = $dayOffset + 22 * 60;
            $windowEnd = $dayOffset + 30 * 60; // 06:00 the following morning
            $hours += max(0, min($to, $windowEnd) - max($from, $windowStart)) / 60.0;
        }

        return $hours;
    }

    private function minutesOf(string $hhmm): int {
        [$h, $m] = array_map('intval', explode(':', substr($hhmm, 0, 5)));
        return $h * 60 + $m;
    }
}
