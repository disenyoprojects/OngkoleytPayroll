<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceAdminControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_admin_can_adjust_attendance_with_a_reason(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();
        $record = AttendanceRecord::factory()->for($employee)->create([
            'clock_in' => '08:10:00', 'clock_out' => '17:00:00', 'status' => 'pending',
        ]);

        $response = $this->actingAs($admin)->patchJson("/api/admin/attendance/{$record->id}/adjust", [
            'clock_in' => '08:00',
            'clock_out' => '17:00',
            'reason' => 'Forgot to Clock In/Out',
            'details' => 'Confirmed via CCTV',
        ]);

        $response->assertOk();
        $record->refresh();
        $this->assertSame('08:00:00', $record->clock_in);
        $this->assertTrue($record->adjusted);
        $this->assertSame('approved', $record->status);
        $this->assertSame(1, AuditLog::where('type', 'attendance')->where('action', 'adjust')->count());
    }

    public function test_adjustment_without_a_reason_is_rejected(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();
        $record = AttendanceRecord::factory()->for($employee)->create();

        $response = $this->actingAs($admin)->patchJson("/api/admin/attendance/{$record->id}/adjust", [
            'clock_in' => '08:00',
            'clock_out' => '17:00',
        ]);

        $response->assertStatus(422);
    }

    public function test_admin_can_approve_a_pending_record(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();
        $record = AttendanceRecord::factory()->for($employee)->create(['status' => 'pending']);

        $response = $this->actingAs($admin)->postJson("/api/admin/attendance/{$record->id}/approve");

        $response->assertOk();
        $this->assertSame('approved', $record->fresh()->status);
    }

    public function test_adjust_persists_day_context_fields(): void {
        $admin = \App\Models\User::factory()->create();
        $employee = \App\Models\Employee::factory()->for(\App\Models\Branch::factory())->create();
        $record = AttendanceRecord::factory()->for($employee)->create([
            'clock_in' => '09:00:00', 'clock_out' => '18:00:00', 'status' => 'pending',
        ]);

        $this->actingAs($admin)->patchJson("/api/admin/attendance/{$record->id}/adjust", [
            'clock_in' => '09:00',
            'clock_out' => '18:00',
            'reason' => 'Special holiday shift',
            'shift_start' => '09:00',
            'shift_end' => '18:00',
            'holiday_type' => 'special',
            'is_rest_day' => true,
            'absence_type' => null,
        ])->assertOk();

        $fresh = $record->fresh();
        $this->assertSame('special', $fresh->holiday_type);
        $this->assertTrue($fresh->is_rest_day);
        $this->assertSame('09:00:00', $fresh->shift_start);
    }

    public function test_bare_clock_adjust_preserves_existing_day_context(): void {
        $admin = \App\Models\User::factory()->create();
        $employee = \App\Models\Employee::factory()->for(\App\Models\Branch::factory())->create();
        $record = AttendanceRecord::factory()->for($employee)->create([
            'clock_in' => '09:00:00', 'clock_out' => '18:00:00', 'status' => 'pending',
            'holiday_type' => 'special', 'break_out' => '13:00:00', 'break_in' => '14:00:00',
        ]);

        $this->actingAs($admin)->patchJson("/api/admin/attendance/{$record->id}/adjust", [
            'clock_in' => '09:05', 'clock_out' => '18:00', 'reason' => 'Fix punch',
        ])->assertOk();

        $fresh = $record->fresh();
        $this->assertSame('special', $fresh->holiday_type);   // preserved
        $this->assertSame('13:00:00', $fresh->break_out);      // preserved (modal never sends it)
        $this->assertSame('14:00:00', $fresh->break_in);       // preserved
    }

    public function test_endpoints_require_admin_authentication(): void {
        $employee = Employee::factory()->for(Branch::factory())->create();
        $record = AttendanceRecord::factory()->for($employee)->create();

        $this->patchJson("/api/admin/attendance/{$record->id}/adjust", ['clock_in' => '08:00', 'clock_out' => '17:00', 'reason' => 'x'])
            ->assertStatus(401);
    }

    public function test_admin_can_manually_add_a_missed_day_with_no_existing_record(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();

        $response = $this->actingAs($admin)->postJson("/api/admin/employees/{$employee->id}/attendance/manual", [
            'work_date' => '2026-08-01',
            'clock_in' => '08:00',
            'clock_out' => '17:00',
            'reason' => 'Forgot to Clock In/Out',
        ]);

        $response->assertCreated();
        $record = AttendanceRecord::where('employee_id', $employee->id)->whereDate('work_date', '2026-08-01')->first();
        $this->assertNotNull($record);
        $this->assertStringStartsWith('08:00', $record->clock_in);
        $this->assertStringStartsWith('17:00', $record->clock_out);
        $this->assertTrue($record->adjusted);
        $this->assertSame('approved', $record->status);
        $this->assertSame(1, AuditLog::where('type', 'attendance')->where('action', 'manual_entry')->count());
    }

    public function test_manual_entry_without_a_reason_is_rejected(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();

        $this->actingAs($admin)->postJson("/api/admin/employees/{$employee->id}/attendance/manual", [
            'work_date' => '2026-08-01', 'clock_in' => '08:00', 'clock_out' => '17:00',
        ])->assertStatus(422);
    }

    public function test_manual_entry_is_rejected_when_a_record_already_exists_for_that_date(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();
        AttendanceRecord::factory()->for($employee)->create(['work_date' => '2026-08-01']);

        $this->actingAs($admin)->postJson("/api/admin/employees/{$employee->id}/attendance/manual", [
            'work_date' => '2026-08-01', 'clock_in' => '08:00', 'clock_out' => '17:00', 'reason' => 'Forgot to Clock In/Out',
        ])->assertStatus(422)->assertJsonValidationErrors('work_date');
    }

    public function test_manual_entry_rejects_a_future_date(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();

        $this->actingAs($admin)->postJson("/api/admin/employees/{$employee->id}/attendance/manual", [
            'work_date' => now()->addDay()->toDateString(), 'clock_in' => '08:00', 'clock_out' => '17:00', 'reason' => 'x',
        ])->assertStatus(422);
    }

    public function test_branch_login_cannot_add_a_manual_entry_for_another_branchs_employee(): void {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $manager = User::factory()->create(['role' => 'branch', 'branch_id' => $branchA->id]);
        $employee = Employee::factory()->for($branchB)->create();

        $this->actingAs($manager)->postJson("/api/admin/employees/{$employee->id}/attendance/manual", [
            'work_date' => '2026-08-01', 'clock_in' => '08:00', 'clock_out' => '17:00', 'reason' => 'x',
        ])->assertStatus(403);
    }

    public function test_manual_entry_endpoint_requires_authentication(): void {
        $employee = Employee::factory()->for(Branch::factory())->create();

        $this->postJson("/api/admin/employees/{$employee->id}/attendance/manual", [
            'work_date' => '2026-08-01', 'clock_in' => '08:00', 'clock_out' => '17:00', 'reason' => 'x',
        ])->assertStatus(401);
    }
}
