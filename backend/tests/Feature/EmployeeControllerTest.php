<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeControllerTest extends TestCase {
    use RefreshDatabase;

    private function payload(Branch $branch, array $overrides = []): array {
        return array_merge([
            'employee_code' => 'ONG-5001',
            'full_name' => 'Test Person',
            'short_name' => 'Test',
            'role' => 'Barista',
            'branch_id' => $branch->id,
            'employment_type' => 'regular',
            'hire_date' => '2026-01-01',
            'pin' => '1234',
        ], $overrides);
    }

    public function test_list_returns_employees_with_branch(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();

        $this->actingAs($admin)->getJson('/api/admin/employees')
            ->assertOk()
            ->assertJsonPath('0.id', $employee->id)
            ->assertJsonPath('0.branch.id', $employee->branch_id);
    }

    public function test_branches_endpoint_returns_id_and_name(): void {
        $admin = User::factory()->create();
        $branch = Branch::factory()->create(['name' => 'Bonifacio']);

        $this->actingAs($admin)->getJson('/api/admin/branches')
            ->assertOk()
            ->assertJsonPath('0.name', 'Bonifacio');
    }

    public function test_create_persists_employee_and_hashes_pin(): void {
        $admin = User::factory()->create();
        $branch = Branch::factory()->create();

        $response = $this->actingAs($admin)->postJson('/api/admin/employees', $this->payload($branch));

        $response->assertCreated();
        $employee = Employee::where('employee_code', 'ONG-5001')->firstOrFail();
        $this->assertTrue($employee->verifyPin('1234'));
    }

    public function test_create_rejects_duplicate_employee_code(): void {
        $admin = User::factory()->create();
        $branch = Branch::factory()->create();
        Employee::factory()->for($branch)->create(['employee_code' => 'ONG-5001']);

        $this->actingAs($admin)->postJson('/api/admin/employees', $this->payload($branch))
            ->assertStatus(422);
    }

    public function test_create_with_daily_rate_requires_reason(): void {
        $admin = User::factory()->create();
        $branch = Branch::factory()->create();

        $this->actingAs($admin)->postJson('/api/admin/employees', $this->payload($branch, [
            'daily_basic_rate' => 620,
        ]))->assertStatus(422);
    }

    public function test_create_with_daily_rate_and_whitespace_reason_is_rejected(): void {
        $admin = User::factory()->create();
        $branch = Branch::factory()->create();

        $this->actingAs($admin)->postJson('/api/admin/employees', $this->payload($branch, [
            'daily_basic_rate' => 620,
            'reason' => '   ',
        ]))->assertStatus(422);
    }

    public function test_create_with_daily_rate_and_reason_writes_audit_log(): void {
        $admin = User::factory()->create();
        $branch = Branch::factory()->create();

        $this->actingAs($admin)->postJson('/api/admin/employees', $this->payload($branch, [
            'daily_basic_rate' => 620,
            'reason' => 'Shift lead premium',
        ]))->assertCreated();

        $this->assertSame(1, AuditLog::where('type', 'employee')->where('action', 'rate_override_set')->count());
    }

    public function test_endpoints_require_authentication(): void {
        $this->getJson('/api/admin/employees')->assertStatus(401);
    }
}
