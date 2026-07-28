<?php

namespace App\Services;

class DoleRates {
    /**
     * Multiplier applied to REGULAR-hour pay for the day's nature.
     * Ordinary 1.00, rest day 1.30, special 1.30, special+rest 1.50,
     * regular holiday 2.00, regular holiday+rest 2.60.
     */
    public static function regularMultiplier(?string $holidayType, bool $isRestDay): float {
        if ($holidayType === 'regular') {
            return $isRestDay ? 2.60 : 2.00;
        }
        if ($holidayType === 'special') {
            return $isRestDay ? 1.50 : 1.30;
        }
        return $isRestDay ? 1.30 : 1.00;
    }

    /**
     * Extra factor applied to the ordinary OT rate for the day's nature.
     * Ordinary 1.00 (OT already 1.25), rest/special 1.30, regular holiday 2.00,
     * regular holiday+rest 2.60. (DOLE OT-on-premium-day is 30% of the premium hourly.)
     */
    public static function overtimeFactor(?string $holidayType, bool $isRestDay): float {
        if ($holidayType === 'regular') {
            return $isRestDay ? 2.60 : 2.00;
        }
        if ($holidayType === 'special') {
            return $isRestDay ? 1.50 : 1.30;
        }
        return $isRestDay ? 1.30 : 1.00;
    }

    public static function label(?string $holidayType, bool $isRestDay): string {
        $parts = [];
        if ($holidayType === 'regular') { $parts[] = 'Regular Holiday'; }
        if ($holidayType === 'special') { $parts[] = 'Special Holiday'; }
        if ($isRestDay) { $parts[] = 'Rest Day'; }
        return $parts ? implode(' + ', $parts) : 'Ordinary';
    }
}
