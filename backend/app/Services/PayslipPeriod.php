<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class PayslipPeriod {
    public static function resolve(string $month, string $period): array {
        [$year, $monthNum] = explode('-', $month);
        $year = (int) $year;
        $monthNum = (int) $monthNum;

        $start = Carbon::createFromDate($year, $monthNum, 1);
        $monthStr = $start->format('M');
        $lastDay = $start->daysInMonth;

        [$fromDay, $toDay, $label] = match ($period) {
            'first' => [1, 15, $monthStr . ' 1–15, ' . $year],
            'second' => [16, $lastDay, $monthStr . ' 16–' . $lastDay . ', ' . $year],
            default => [1, $lastDay, $start->format('F Y')],
        };

        return [
            'label' => $label,
            'from' => $year . '-' . str_pad($monthNum, 2, '0', STR_PAD_LEFT) . '-' . str_pad($fromDay, 2, '0', STR_PAD_LEFT),
            'to' => $year . '-' . str_pad($monthNum, 2, '0', STR_PAD_LEFT) . '-' . str_pad($toDay, 2, '0', STR_PAD_LEFT),
        ];
    }
}
