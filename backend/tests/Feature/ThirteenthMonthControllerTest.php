<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\ThirteenthMonthRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThirteenthMonthControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_index_lists_eligible_employees_as_pending_by_default(): void {
        $admin = User::factory()->create();
        Employee::factory()->for(Branch::factory())->create(['hire_date' => '2026-01-01', 'employment_type' => 'regular']);

        $response = $this->actingAs($admin)->getJson('/api/admin/thirteenth-month?year=2026');

        $response->assertOk();
        $this->assertCount(1, $response->json('records'));
        $this->assertSame('pending', $response->json('records.0.status'));
    }

    public function test_compute_moves_a_record_to_computed_and_sets_the_amount(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['hire_date' => '2026-01-01', 'employment_type' => 'regular']);

        $response = $this->actingAs($admin)->postJson("/api/admin/thirteenth-month/{$employee->id}/compute?year=2026");

        $response->assertOk();
        $this->assertDatabaseHas('thirteenth_month_records', [
            'employee_id' => $employee->id, 'payroll_year' => 2026, 'status' => 'computed',
        ]);
    }

    public function test_compute_all_computes_every_pending_eligible_employee(): void {
        $admin = User::factory()->create();
        Employee::factory()->count(3)->for(Branch::factory())->create(['hire_date' => '2026-01-01', 'employment_type' => 'regular']);

        $response = $this->actingAs($admin)->postJson('/api/admin/thirteenth-month/compute-all?year=2026');

        $response->assertOk();
        $this->assertSame(3, ThirteenthMonthRecord::where('status', 'computed')->count());
    }
}
