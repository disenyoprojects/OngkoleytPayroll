<?php

namespace Tests\Unit;

use App\Models\PayrollSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollSettingModelTest extends TestCase {
    use RefreshDatabase;

    public function test_current_creates_a_default_row_when_none_exists(): void {
        $this->assertSame(0, PayrollSetting::count());

        $settings = PayrollSetting::current();

        $this->assertSame(1, PayrollSetting::count());
        $this->assertSame(['BASIC'], $settings->included_earnings);
        $this->assertSame(505.0, (float) $settings->daily_basic_rate);
    }

    public function test_current_returns_the_existing_row_on_subsequent_calls(): void {
        $first = PayrollSetting::current();
        $second = PayrollSetting::current();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PayrollSetting::count());
    }
}
