<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceDashboardControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_returns_todays_stats_and_rows(): void {
        $admin = User::factory()->create();
        $branch = Branch::factory()->create();
        $approved = Employee::factory()->for($branch)->create();
        $pending = Employee::factory()->for($branch)->create();

        AttendanceRecord::factory()->for($approved)->create(['clock_in' => '08:00:00', 'clock_out' => '17:00:00', 'status' => 'approved']);
        AttendanceRecord::factory()->for($pending)->create(['clock_in' => '08:00:00', 'clock_out' => null, 'status' => 'pending']);

        $response = $this->actingAs($admin)->getJson('/api/admin/attendance/today');

        $response->assertOk();
        $response->assertJsonPath('clocked_in', 2);
        $response->assertJsonPath('pending', 1);
        $response->assertJsonPath('approved', 1);
        $this->assertCount(2, $response->json('rows'));
    }
}
