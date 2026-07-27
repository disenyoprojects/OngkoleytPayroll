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

    public function test_create_persists_shift_times_and_defaults(): void {
        $admin = User::factory()->create();
        $branch = Branch::factory()->create();

        // Explicit shift.
        $this->actingAs($admin)->postJson('/api/admin/employees', $this->payload($branch, [
            'employee_code' => 'ONG-5002',
            'shift_start' => '13:00',
            'shift_end' => '22:00',
        ]))->assertCreated();
        $emp = Employee::where('employee_code', 'ONG-5002')->firstOrFail();
        $this->assertSame('13:00:00', $emp->shift_start);
        $this->assertSame('22:00:00', $emp->shift_end);

        // Omitted shift falls back to the 08:00-17:00 default.
        $this->actingAs($admin)->postJson('/api/admin/employees', $this->payload($branch, [
            'employee_code' => 'ONG-5003',
        ]))->assertCreated();
        $def = Employee::where('employee_code', 'ONG-5003')->firstOrFail();
        $this->assertSame('08:00:00', $def->shift_start);
        $this->assertSame('17:00:00', $def->shift_end);
    }

    public function test_update_changes_shift_times(): void {
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
            'shift_start' => '09:30',
            'shift_end' => '18:30',
        ])->assertOk();

        $this->assertSame('09:30:00', $employee->fresh()->shift_start);
        $this->assertSame('18:30:00', $employee->fresh()->shift_end);
    }

    public function test_create_with_daily_rate_needs_no_reason_and_still_audits(): void {
        $admin = User::factory()->create();
        $branch = Branch::factory()->create();

        $this->actingAs($admin)->postJson('/api/admin/employees', $this->payload($branch, [
            'daily_basic_rate' => 620,
        ]))->assertCreated();

        $this->assertSame(1, AuditLog::where('type', 'employee')->where('action', 'rate_override_set')->count());
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

    public function test_update_changing_rate_needs_no_reason_and_still_audits(): void {
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
        ])->assertOk();

        $this->assertSame('700.00', $employee->fresh()->daily_basic_rate);
        $this->assertSame(1, AuditLog::where('employee_id', $employee->id)->where('action', 'rate_override_changed')->count());
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

    public function test_separate_archives_employee_and_sets_type_reason_and_audit(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();
        AttendanceRecord::factory()->for($employee)->create();

        $this->actingAs($admin)->postJson("/api/admin/employees/{$employee->id}/separate", [
            'separation_type' => 'improper',
            'reason' => 'Abandoned post',
        ])->assertOk();

        $fresh = Employee::withTrashed()->find($employee->id);
        $this->assertNotNull($fresh->deleted_at);
        $this->assertSame('improper', $fresh->separation_type);
        $this->assertSame('Abandoned post', $fresh->separation_reason);
        $this->assertNotNull($fresh->resignation_date);
        $this->assertSame(1, AuditLog::where('type', 'employee')->where('action', 'separated')->count());
    }

    public function test_separate_requires_reason_and_valid_type(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();

        $this->actingAs($admin)->postJson("/api/admin/employees/{$employee->id}/separate", [
            'separation_type' => 'improper',
            'reason' => '   ',
        ])->assertStatus(422);

        $other = Employee::factory()->for(Branch::factory())->create();
        $this->actingAs($admin)->postJson("/api/admin/employees/{$other->id}/separate", [
            'separation_type' => 'quit',
            'reason' => 'x',
        ])->assertStatus(422);
    }

    public function test_active_index_excludes_separated_and_separated_list_includes_it(): void {
        $admin = User::factory()->create();
        $active = Employee::factory()->for(Branch::factory())->create(['short_name' => 'ActiveOne']);
        $gone = Employee::factory()->for(Branch::factory())->create(['short_name' => 'GoneOne']);

        $this->actingAs($admin)->postJson("/api/admin/employees/{$gone->id}/separate", [
            'separation_type' => 'proper',
            'reason' => 'Formal resignation',
        ])->assertOk();

        $indexIds = collect($this->actingAs($admin)->getJson('/api/admin/employees')->json())->pluck('id');
        $this->assertTrue($indexIds->contains($active->id));
        $this->assertFalse($indexIds->contains($gone->id));

        $separatedIds = collect($this->actingAs($admin)->getJson('/api/admin/employees/separated')->json())->pluck('id');
        $this->assertTrue($separatedIds->contains($gone->id));
        $this->assertFalse($separatedIds->contains($active->id));
    }

    public function test_separated_list_filters_by_type(): void {
        $admin = User::factory()->create();
        $proper = Employee::factory()->for(Branch::factory())->create();
        $improper = Employee::factory()->for(Branch::factory())->create();

        $this->actingAs($admin)->postJson("/api/admin/employees/{$proper->id}/separate", [
            'separation_type' => 'proper', 'reason' => 'notice given',
        ])->assertOk();
        $this->actingAs($admin)->postJson("/api/admin/employees/{$improper->id}/separate", [
            'separation_type' => 'improper', 'reason' => 'awol',
        ])->assertOk();

        $improperIds = collect($this->actingAs($admin)->getJson('/api/admin/employees/separated?type=improper')->json())->pluck('id');
        $this->assertTrue($improperIds->contains($improper->id));
        $this->assertFalse($improperIds->contains($proper->id));
    }

    public function test_restore_reactivates_employee_and_clears_separation_fields(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();

        $this->actingAs($admin)->postJson("/api/admin/employees/{$employee->id}/separate", [
            'separation_type' => 'improper', 'reason' => 'awol',
        ])->assertOk();

        $this->actingAs($admin)->postJson("/api/admin/employees/{$employee->id}/restore")
            ->assertOk();

        $fresh = Employee::withTrashed()->find($employee->id);
        $this->assertNull($fresh->deleted_at);
        $this->assertNull($fresh->separation_type);
        $this->assertNull($fresh->separation_reason);
        $this->assertNull($fresh->resignation_date);
        $this->assertSame(1, AuditLog::where('type', 'employee')->where('action', 'restored')->count());

        $indexIds = collect($this->actingAs($admin)->getJson('/api/admin/employees')->json())->pluck('id');
        $this->assertTrue($indexIds->contains($employee->id));
    }
}
