<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessTest extends TestCase {
    use RefreshDatabase;

    private Branch $mabini;
    private Branch $diego;
    private Employee $mabiniStaff;
    private Employee $diegoStaff;
    private User $branchUser; // scoped to Mabini
    private User $admin;

    protected function setUp(): void {
        parent::setUp();
        PayrollSetting::current();
        $this->mabini = Branch::factory()->create(['name' => 'Mabini']);
        $this->diego = Branch::factory()->create(['name' => 'Diego']);
        $this->mabiniStaff = Employee::factory()->for($this->mabini)->create(['short_name' => 'Mabs', 'daily_basic_rate' => null]);
        $this->diegoStaff = Employee::factory()->for($this->diego)->create(['short_name' => 'Digs', 'daily_basic_rate' => null]);
        $this->branchUser = User::factory()->create(['role' => 'branch', 'branch_id' => $this->mabini->id]);
        $this->admin = User::factory()->create(['role' => 'admin', 'branch_id' => null]);
    }

    public function test_branch_login_sees_only_its_own_branch_employees(): void {
        $ids = collect($this->actingAs($this->branchUser)->getJson('/api/admin/employees')->assertOk()->json())->pluck('id');
        $this->assertTrue($ids->contains($this->mabiniStaff->id));
        $this->assertFalse($ids->contains($this->diegoStaff->id));
    }

    public function test_admin_sees_all_branches(): void {
        $ids = collect($this->actingAs($this->admin)->getJson('/api/admin/employees')->assertOk()->json())->pluck('id');
        $this->assertTrue($ids->contains($this->mabiniStaff->id));
        $this->assertTrue($ids->contains($this->diegoStaff->id));
    }

    public function test_branch_login_cannot_open_another_branchs_payslip(): void {
        $this->actingAs($this->branchUser)
            ->getJson("/api/admin/employees/{$this->diegoStaff->id}/payslip?month=2026-07&period=second")
            ->assertStatus(403);

        // Its own branch is fine.
        $this->actingAs($this->branchUser)
            ->getJson("/api/admin/employees/{$this->mabiniStaff->id}/payslip?month=2026-07&period=second")
            ->assertOk();
    }

    public function test_branch_login_cannot_clock_another_branchs_staff(): void {
        $this->actingAs($this->branchUser)
            ->postJson('/api/admin/clock/in', ['employee_id' => $this->diegoStaff->id])
            ->assertStatus(403);
    }

    public function test_register_is_scoped_to_the_branch(): void {
        AttendanceRecord::factory()->for($this->mabiniStaff)->create([
            'work_date' => '2026-07-20', 'clock_in' => '08:00:00', 'clock_out' => '17:00:00',
        ]);
        AttendanceRecord::factory()->for($this->diegoStaff)->create([
            'work_date' => '2026-07-20', 'clock_in' => '08:00:00', 'clock_out' => '17:00:00',
        ]);

        $rows = $this->actingAs($this->branchUser)
            ->getJson('/api/admin/payroll/period?month=2026-07&period=second')->assertOk()->json('rows');

        $this->assertCount(1, $rows);
        $this->assertSame('Mabs', $rows[0]['name']);
    }

    public function test_branch_login_is_blocked_from_admin_only_sections(): void {
        $this->actingAs($this->branchUser)->getJson('/api/admin/settings')->assertStatus(403);
        $this->actingAs($this->branchUser)->getJson('/api/admin/audit-log')->assertStatus(403);
        $this->actingAs($this->branchUser)->getJson('/api/admin/thirteenth-month')->assertStatus(403);
        $this->actingAs($this->branchUser)->postJson('/api/admin/employees', [])->assertStatus(403);
    }

    public function test_admin_can_reach_admin_only_sections(): void {
        $this->actingAs($this->admin)->getJson('/api/admin/settings')->assertOk();
    }

    public function test_me_reports_role_and_branch(): void {
        $this->actingAs($this->branchUser)->getJson('/api/admin/me')
            ->assertOk()
            ->assertJsonPath('role', 'branch')
            ->assertJsonPath('branch', 'Mabini');

        $this->actingAs($this->admin)->getJson('/api/admin/me')
            ->assertOk()
            ->assertJsonPath('role', 'admin');
    }
}
