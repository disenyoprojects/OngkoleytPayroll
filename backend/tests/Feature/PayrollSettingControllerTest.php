<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollSettingControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_get_returns_the_current_settings(): void {
        $admin = User::factory()->create();

        $response = $this->actingAs($admin)->getJson('/api/admin/settings');

        $response->assertOk();
        $response->assertJsonPath('daily_basic_rate', '505.00');
    }

    public function test_put_updates_the_settings_and_applies_immediately(): void {
        $admin = User::factory()->create();

        $response = $this->actingAs($admin)->putJson('/api/admin/settings', [
            'daily_basic_rate' => 600,
            'standard_working_days_per_month' => 26,
            'overtime_multiplier' => 1.25,
            'night_diff_multiplier' => 0.10,
            'unpaid_break_hours' => 1.00,
            'late_penalty_amount' => 75,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'release_date' => '2026-12-24',
            'minimum_months' => 1,
            'included_earnings' => ['BASIC'],
            'employment_types_included' => ['regular', 'probationary', 'fixed_term', 'seasonal'],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('payroll_settings', ['daily_basic_rate' => 600.00]);
    }

    public function test_put_rejects_an_unknown_earning_code(): void {
        $admin = User::factory()->create();

        $response = $this->actingAs($admin)->putJson('/api/admin/settings', [
            'daily_basic_rate' => 600,
            'standard_working_days_per_month' => 26,
            'overtime_multiplier' => 1.25,
            'night_diff_multiplier' => 0.10,
            'unpaid_break_hours' => 1.00,
            'late_penalty_amount' => 75,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'release_date' => '2026-12-24',
            'minimum_months' => 1,
            'included_earnings' => ['BASC'],
            'employment_types_included' => ['regular', 'probationary', 'fixed_term', 'seasonal'],
        ]);

        $response->assertStatus(422);
    }

    public function test_put_rejects_an_unknown_employment_type(): void {
        $admin = User::factory()->create();

        $response = $this->actingAs($admin)->putJson('/api/admin/settings', [
            'daily_basic_rate' => 600,
            'standard_working_days_per_month' => 26,
            'overtime_multiplier' => 1.25,
            'night_diff_multiplier' => 0.10,
            'unpaid_break_hours' => 1.00,
            'late_penalty_amount' => 75,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'release_date' => '2026-12-24',
            'minimum_months' => 1,
            'included_earnings' => ['BASIC'],
            'employment_types_included' => ['contractor'],
        ]);

        $response->assertStatus(422);
    }
}
