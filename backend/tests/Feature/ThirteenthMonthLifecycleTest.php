<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\ThirteenthMonthRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThirteenthMonthLifecycleTest extends TestCase {
    use RefreshDatabase;

    private function computedRecord(): array {
        $employee = Employee::factory()->for(Branch::factory())->create();
        $record = ThirteenthMonthRecord::factory()->for($employee)->create([
            'payroll_year' => 2026, 'computed_amount' => 5000, 'status' => 'computed',
        ]);
        return [$employee, $record];
    }

    public function test_adjust_requires_a_reason_and_updates_the_amount(): void {
        $admin = User::factory()->create();
        [$employee, $record] = $this->computedRecord();

        $response = $this->actingAs($admin)->postJson("/api/admin/thirteenth-month/{$employee->id}/adjust?year=2026", [
            'amount' => 500, 'reason' => 'Correction after payroll review',
        ]);

        $response->assertOk();
        $this->assertSame(500.0, (float) $record->fresh()->manual_adjustment);
    }

    public function test_lock_then_release_then_unlock_requires_a_reason(): void {
        $admin = User::factory()->create();
        [$employee, $record] = $this->computedRecord();

        $this->actingAs($admin)->postJson("/api/admin/thirteenth-month/{$employee->id}/release?year=2026")->assertOk();
        $this->assertSame('released', $record->fresh()->status);

        $this->actingAs($admin)->postJson("/api/admin/thirteenth-month/{$employee->id}/lock?year=2026")->assertOk();
        $this->assertSame('locked', $record->fresh()->status);

        $this->actingAs($admin)->postJson("/api/admin/thirteenth-month/{$employee->id}/unlock?year=2026", [])
            ->assertStatus(422);

        $this->actingAs($admin)->postJson("/api/admin/thirteenth-month/{$employee->id}/unlock?year=2026", [
            'reason' => 'Corrected basic salary after payroll error, approved by HR Manager',
        ])->assertOk();
        $this->assertSame('released', $record->fresh()->status);
    }

    private function releasedRecord(): array {
        $employee = Employee::factory()->for(Branch::factory())->create();
        $record = ThirteenthMonthRecord::factory()->for($employee)->create([
            'payroll_year' => 2026, 'computed_amount' => 5000, 'status' => 'released', 'released_on' => '2026-12-24',
        ]);
        return [$employee, $record];
    }

    private function lockedRecord(): array {
        $employee = Employee::factory()->for(Branch::factory())->create();
        $record = ThirteenthMonthRecord::factory()->for($employee)->create([
            'payroll_year' => 2026, 'computed_amount' => 5000, 'status' => 'locked', 'released_on' => '2026-12-24',
        ]);
        return [$employee, $record];
    }

    public function test_recompute_is_rejected_on_a_released_record(): void {
        $admin = User::factory()->create();
        [$employee, $record] = $this->releasedRecord();

        $response = $this->actingAs($admin)->postJson("/api/admin/thirteenth-month/{$employee->id}/recompute?year=2026");

        $response->assertStatus(422);
        $fresh = $record->fresh();
        $this->assertSame('released', $fresh->status);
        $this->assertSame(5000.0, (float) $fresh->computed_amount);
    }

    public function test_adjust_is_rejected_on_a_released_record(): void {
        $admin = User::factory()->create();
        [$employee, $record] = $this->releasedRecord();

        $response = $this->actingAs($admin)->postJson("/api/admin/thirteenth-month/{$employee->id}/adjust?year=2026", [
            'amount' => 500, 'reason' => 'Attempted correction after release',
        ]);

        $response->assertStatus(422);
        $fresh = $record->fresh();
        $this->assertSame('released', $fresh->status);
        $this->assertSame(0.0, (float) $fresh->manual_adjustment);
    }

    public function test_recompute_is_rejected_on_a_locked_record(): void {
        $admin = User::factory()->create();
        [$employee, $record] = $this->lockedRecord();

        $response = $this->actingAs($admin)->postJson("/api/admin/thirteenth-month/{$employee->id}/recompute?year=2026");

        $response->assertStatus(422);
        $fresh = $record->fresh();
        $this->assertSame('locked', $fresh->status);
        $this->assertSame(5000.0, (float) $fresh->computed_amount);
    }
}
