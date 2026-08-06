<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceClockControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_clock_in_creates_a_pending_record_for_today(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();

        $this->actingAs($admin)->postJson('/api/admin/clock/in', ['employee_id' => $employee->id])
            ->assertOk();

        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employee->id,
            'status' => 'pending',
        ]);
    }

    public function test_clock_in_twice_in_a_day_is_rejected(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();

        $this->actingAs($admin)->postJson('/api/admin/clock/in', ['employee_id' => $employee->id])->assertOk();
        $this->actingAs($admin)->postJson('/api/admin/clock/in', ['employee_id' => $employee->id])->assertStatus(422);

        $this->assertSame(1, AttendanceRecord::where('employee_id', $employee->id)->count());
    }

    public function test_clock_out_fills_in_clock_out_time(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();
        $this->actingAs($admin)->postJson('/api/admin/clock/in', ['employee_id' => $employee->id]);

        $this->actingAs($admin)->postJson('/api/admin/clock/out', ['employee_id' => $employee->id])->assertOk();

        $record = AttendanceRecord::where('employee_id', $employee->id)->firstOrFail();
        $this->assertNotNull($record->clock_out);
    }

    public function test_clock_out_without_clocking_in_is_rejected(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();

        $this->actingAs($admin)->postJson('/api/admin/clock/out', ['employee_id' => $employee->id])->assertStatus(422);
    }

    public function test_clock_endpoints_require_authentication(): void {
        $employee = Employee::factory()->for(Branch::factory())->create();

        $this->postJson('/api/admin/clock/in', ['employee_id' => $employee->id])->assertStatus(401);
    }

    public function test_clock_in_stamps_employee_shift_on_record(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create([
            'shift_start' => '14:00:00', 'shift_end' => '23:00:00',
        ]);

        $this->actingAs($admin)->postJson('/api/admin/clock/in', ['employee_id' => $employee->id])->assertOk();

        $record = AttendanceRecord::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('14:00:00', $record->shift_start);
        $this->assertSame('23:00:00', $record->shift_end);
    }

    public function test_clock_out_does_not_overwrite_an_adjusted_shift(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create([
            'shift_start' => '14:00:00', 'shift_end' => '23:00:00',
        ]);
        $this->actingAs($admin)->postJson('/api/admin/clock/in', ['employee_id' => $employee->id])->assertOk();

        $record = AttendanceRecord::where('employee_id', $employee->id)->firstOrFail();
        $record->update(['shift_start' => '09:00:00', 'shift_end' => '18:00:00']);

        $this->actingAs($admin)->postJson('/api/admin/clock/out', ['employee_id' => $employee->id])->assertOk();

        $record->refresh();
        $this->assertSame('09:00:00', $record->shift_start);
        $this->assertSame('18:00:00', $record->shift_end);
    }

    public function test_staff_lists_employees_with_branch(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['short_name' => 'Alex']);

        $this->actingAs($admin)->getJson('/api/admin/clock/staff')
            ->assertOk()
            ->assertJsonPath('0.short_name', 'Alex')
            ->assertJsonPath('0.id', $employee->id);
    }

    public function test_staff_requires_authentication(): void {
        $this->getJson('/api/admin/clock/staff')->assertStatus(401);
    }

    public function test_clock_in_uses_a_client_supplied_timestamp(): void {
        // Simulates an offline clock-in synced later: the record must land on
        // the day it actually happened, not the day it happened to sync.
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();
        $clockedAt = now()->subDay()->setTime(9, 15, 0);

        $this->actingAs($admin)->postJson('/api/admin/clock/in', [
            'employee_id' => $employee->id,
            'clocked_at' => $clockedAt->toIso8601String(),
        ])->assertOk();

        $record = AttendanceRecord::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame($clockedAt->toDateString(), $record->work_date->toDateString());
        $this->assertSame('09:15:00', $record->clock_in);
    }

    public function test_clock_out_uses_a_client_supplied_timestamp_on_the_same_work_date(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();
        $clockedIn = now()->subDay()->setTime(9, 0, 0);
        $clockedOut = now()->subDay()->setTime(17, 30, 0);

        $this->actingAs($admin)->postJson('/api/admin/clock/in', [
            'employee_id' => $employee->id, 'clocked_at' => $clockedIn->toIso8601String(),
        ])->assertOk();
        $this->actingAs($admin)->postJson('/api/admin/clock/out', [
            'employee_id' => $employee->id, 'clocked_at' => $clockedOut->toIso8601String(),
        ])->assertOk();

        $record = AttendanceRecord::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('17:30:00', $record->clock_out);
    }

    public function test_clock_in_rejects_a_clocked_at_too_far_in_the_past(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();

        $this->actingAs($admin)->postJson('/api/admin/clock/in', [
            'employee_id' => $employee->id,
            'clocked_at' => now()->subDays(10)->toIso8601String(),
        ])->assertStatus(422);
    }

    public function test_clock_in_rejects_a_clocked_at_far_in_the_future(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();

        $this->actingAs($admin)->postJson('/api/admin/clock/in', [
            'employee_id' => $employee->id,
            'clocked_at' => now()->addHour()->toIso8601String(),
        ])->assertStatus(422);
    }

    public function test_status_returns_todays_record(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();
        $this->actingAs($admin)->postJson('/api/admin/clock/in', ['employee_id' => $employee->id]);

        $this->actingAs($admin)->getJson("/api/admin/clock/status?employee_id={$employee->id}")
            ->assertOk()
            ->assertJsonPath('employee_id', $employee->id);
    }
}
