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

        // 8 regular hours at 505/8 = 63.125/hr => 505.00 basic
        $this->assertSame(505.00, $pay['basic']);
    }

    public function test_uses_override_rate_when_provided(): void {
        $calc = new AttendancePayCalculator();

        $pay = $calc->compute('08:00', '16:00', $this->settings(), 800.00);

        // 8 regular hours at 800/8 = 100/hr => 800.00 basic
        $this->assertSame(800.00, $pay['basic']);
    }
}
