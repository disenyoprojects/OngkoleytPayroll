<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
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

    public function test_update_without_rate_change_needs_no_reason(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['role' => 'Barista']);

        $this->actingAs($admin)->putJson("/api/admin/employees/{$employee->id}", [
            'employee_code' => $employee->employee_code,
            'full_name' => $employee->full_name,
            'short_name' => $employee->short_name,
            'role' => 'Head Barista',
            'branch_id' => $employee->branch_id,
            'employment_type' => $employee->employment_type,
            'hire_date' => $employee->hire_date->toDateString(),
        ])->assertOk();

        $this->assertSame('Head Barista', $employee->fresh()->role);
    }

    public function test_update_changing_rate_requires_reason(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => null]);

        $this->actingAs($admin)->putJson("/api/admin/employees/{$employee->id}", [
            'employee_code' => $employee->employee_code,
            'full_name' => $employee->full_name,
            'short_name' => $employee->short_name,
            'role' => $employee->role,
            'branch_id' => $employee->branch_id,
            'employment_type' => $employee->employment_type,
            'hire_date' => $employee->hire_date->toDateString(),
            'daily_basic_rate' => 700,
        ])->assertStatus(422);
    }

    public function test_update_changing_rate_with_whitespace_reason_is_rejected(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => 500]);

        $this->actingAs($admin)->putJson("/api/admin/employees/{$employee->id}", [
            'employee_code' => $employee->employee_code,
            'full_name' => $employee->full_name,
            'short_name' => $employee->short_name,
            'role' => $employee->role,
            'branch_id' => $employee->branch_id,
            'employment_type' => $employee->employment_type,
            'hire_date' => $employee->hire_date->toDateString(),
            'daily_basic_rate' => 700,
            'reason' => '   ',
        ])->assertStatus(422);
    }

    public function test_update_changing_rate_with_reason_writes_audit_log(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => 500]);

        $this->actingAs($admin)->putJson("/api/admin/employees/{$employee->id}", [
            'employee_code' => $employee->employee_code,
            'full_name' => $employee->full_name,
            'short_name' => $employee->short_name,
            'role' => $employee->role,
            'branch_id' => $employee->branch_id,
            'employment_type' => $employee->employment_type,
            'hire_date' => $employee->hire_date->toDateString(),
            'daily_basic_rate' => 700,
            'reason' => 'Promotion',
        ])->assertOk();

        $this->assertSame('700.00', $employee->fresh()->daily_basic_rate);
        $log = AuditLog::where('type', 'employee')->where('action', 'rate_override_changed')->firstOrFail();
        $this->assertSame('500.00', $log->old_amount);
        $this->assertSame('700.00', $log->new_amount);
    }

    public function test_update_with_blank_pin_keeps_existing_pin(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();

        $this->actingAs($admin)->putJson("/api/admin/employees/{$employee->id}", [
            'employee_code' => $employee->employee_code,
            'full_name' => $employee->full_name,
            'short_name' => $employee->short_name,
            'role' => $employee->role,
            'branch_id' => $employee->branch_id,
            'employment_type' => $employee->employment_type,
            'hire_date' => $employee->hire_date->toDateString(),
            'pin' => '',
        ])->assertOk();

        $this->assertTrue($employee->fresh()->verifyPin('1234'));
    }

    public function test_update_omitting_daily_rate_key_preserves_existing_rate(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => 500]);

        $this->actingAs($admin)->putJson("/api/admin/employees/{$employee->id}", [
            'employee_code' => $employee->employee_code,
            'full_name' => $employee->full_name,
            'short_name' => $employee->short_name,
            'role' => 'Updated Role',
            'branch_id' => $employee->branch_id,
            'employment_type' => $employee->employment_type,
            'hire_date' => $employee->hire_date->toDateString(),
            // daily_basic_rate intentionally omitted
        ])->assertOk();

        $this->assertSame('500.00', $employee->fresh()->daily_basic_rate);
        $this->assertSame('Updated Role', $employee->fresh()->role);
        $this->assertSame(0, AuditLog::where('employee_id', $employee->id)->where('action', 'rate_override_changed')->count());
    }

    public function test_delete_succeeds_when_employee_has_no_history(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();

        $this->actingAs($admin)->deleteJson("/api/admin/employees/{$employee->id}")
            ->assertOk();

        $this->assertDatabaseMissing('employees', ['id' => $employee->id]);
        $this->assertSame(1, AuditLog::where('type', 'employee')->where('action', 'deleted')->count());
    }

    public function test_delete_is_refused_when_employee_has_attendance(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();
        AttendanceRecord::factory()->for($employee)->create();

        $this->actingAs($admin)->deleteJson("/api/admin/employees/{$employee->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('employees', ['id' => $employee->id]);
    }
}
