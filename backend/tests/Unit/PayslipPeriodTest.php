<?php

namespace Tests\Unit;

use App\Services\PayslipPeriod;
use Tests\TestCase;

class PayslipPeriodTest extends TestCase {
    public function test_first_half_is_1_to_15(): void {
        $p = PayslipPeriod::resolve('2026-07', 'first');
        $this->assertSame('2026-07-01', $p['from']);
        $this->assertSame('2026-07-15', $p['to']);
    }

    public function test_second_half_runs_to_end_of_a_31_day_month(): void {
        $p = PayslipPeriod::resolve('2026-07', 'second');
        $this->assertSame('2026-07-16', $p['from']);
        $this->assertSame('2026-07-31', $p['to']);
    }

    public function test_second_half_runs_to_end_of_a_30_day_month(): void {
        $p = PayslipPeriod::resolve('2026-06', 'second');
        $this->assertSame('2026-06-16', $p['from']);
        $this->assertSame('2026-06-30', $p['to']);
    }

    public function test_second_half_handles_february(): void {
        $p = PayslipPeriod::resolve('2026-02', 'second');
        $this->assertSame('2026-02-16', $p['from']);
        $this->assertSame('2026-02-28', $p['to']);
    }

    public function test_whole_month_is_first_to_last_day(): void {
        $p = PayslipPeriod::resolve('2026-07', 'whole');
        $this->assertSame('2026-07-01', $p['from']);
        $this->assertSame('2026-07-31', $p['to']);
    }
}
