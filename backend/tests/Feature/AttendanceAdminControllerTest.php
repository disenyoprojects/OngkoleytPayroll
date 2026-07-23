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

    public function test_endpoints_require_admin_authentication(): void {
        $employee = Employee::factory()->for(Branch::factory())->create();
        $record = AttendanceRecord::factory()->for($employee)->create();

        $this->patchJson("/api/admin/attendance/{$record->id}/adjust", ['clock_in' => '08:00', 'clock_out' => '17:00', 'reason' => 'x'])
            ->assertStatus(401);
    }
}
