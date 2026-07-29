<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceDashboardDateTest extends TestCase {
    use RefreshDatabase;

    public function test_returns_only_the_requested_date(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();
        AttendanceRecord::factory()->for($employee)->create(['work_date' => '2026-07-15', 'clock_in' => '08:00:00', 'clock_out' => '17:00:00']);
        AttendanceRecord::factory()->for($employee)->create(['work_date' => '2026-07-16', 'clock_in' => '08:00:00', 'clock_out' => '17:00:00']);

        $response = $this->actingAs($admin)->getJson('/api/admin/attendance/today?date=2026-07-15');

        $response->assertOk();
        $this->assertSame('2026-07-15', $response->json('date'));
        $this->assertSame(1, $response->json('clocked_in'));
        $this->assertCount(1, $response->json('rows'));
    }

    public function test_defaults_to_today_when_no_date_given(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();
        AttendanceRecord::factory()->for($employee)->create(['work_date' => now()->toDateString(), 'clock_in' => '08:00:00', 'clock_out' => '17:00:00']);
        AttendanceRecord::factory()->for($employee)->create(['work_date' => '2020-01-01', 'clock_in' => '08:00:00', 'clock_out' => '17:00:00']);

        $response = $this->actingAs($admin)->getJson('/api/admin/attendance/today');

        $response->assertOk();
        $this->assertSame(now()->toDateString(), $response->json('date'));
        $this->assertSame(1, $response->json('clocked_in'));
    }

    public function test_rejects_a_malformed_date(): void {
        $admin = User::factory()->create();

        $this->actingAs($admin)->getJson('/api/admin/attendance/today?date=07-2026')
            ->assertStatus(422);
    }
}
