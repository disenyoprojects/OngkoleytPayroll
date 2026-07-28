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
}
