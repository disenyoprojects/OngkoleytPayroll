<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollSetting extends Model {
    protected $fillable = [
        'daily_basic_rate', 'standard_working_days_per_month', 'overtime_multiplier',
        'night_diff_multiplier', 'unpaid_break_hours', 'period_start', 'period_end', 'release_date',
        'minimum_months', 'included_earnings', 'employment_types_included',
    ];
    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'release_date' => 'date',
        'included_earnings' => 'array',
        'employment_types_included' => 'array',
    ];

    public static function current(): self {
        $existing = static::first();
        if ($existing) {
            return $existing;
        }
        $settings = static::create([
            'period_start' => now()->startOfYear(),
            'period_end' => now()->endOfYear(),
            'release_date' => now()->endOfYear(),
            'included_earnings' => ['BASIC'],
            'employment_types_included' => ['regular', 'probationary', 'fixed_term', 'seasonal'],
        ]);

        // Reload so DB-generated column defaults (daily_basic_rate, etc.) are
        // reflected on the returned instance, not just the DB row.
        return $settings->fresh();
    }
}
