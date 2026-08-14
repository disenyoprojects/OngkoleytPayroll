<?php

namespace Tests\Unit;

use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use Tests\TestCase;

class AttendancePayCalculatorOverrideTest extends TestCase {
    private function settings(): PayrollSetting {
        return new PayrollSetting([
            'daily_basic_rate' => 505.00,
            'overtime_multiplier' => 1.25,
            'night_diff_multiplier' => 0.10,
        ]);
    }

    public function test_uses_global_rate_when_override_is_null(): void {
        $calc = new AttendancePayCalculator();

        $pay = $calc->compute('08:00', '16:00', $this->settings(), null);

        // 08:00-16:00 against the default 08:00-17:00 shift and no break
        // setting: 9h paid, 1h charged back as undertime => 8h at 505/8.
        $this->assertSame(505.00, round($pay['total'], 2));
    }

    public function test_uses_override_rate_when_provided(): void {
        $calc = new AttendancePayCalculator();

        $pay = $calc->compute('08:00', '16:00', $this->settings(), 800.00);

        // Same 8 net hours, now at 800/8 = 100/hr.
        $this->assertSame(800.00, round($pay['total'], 2));
    }
}
