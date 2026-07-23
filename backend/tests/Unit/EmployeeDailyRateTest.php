<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeDailyRateTest extends TestCase {
    use RefreshDatabase;

    public function test_daily_basic_rate_defaults_to_null(): void {
        $employee = Employee::factory()->for(Branch::factory())->create();

        $this->assertNull($employee->fresh()->daily_basic_rate);
    }

    public function test_daily_basic_rate_persists_when_set(): void {
        $employee = Employee::factory()->for(Branch::factory())->create([
            'daily_basic_rate' => 620.50,
        ]);

        $this->assertSame('620.50', $employee->fresh()->daily_basic_rate);
    }
}
