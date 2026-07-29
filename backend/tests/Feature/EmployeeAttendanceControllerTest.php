<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeAttendanceControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_returns_only_the_requested_month_for_the_employee(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();
        AttendanceRecord::factory()->for($employee)->create(['work_date' => '2026-07-05', 'clock_in' => '08:00:00', 'clock_out' => '17:00:00']);
        AttendanceRecord::factory()->for($employee)->create(['work_date' => '2026-08-05', 'clock_in' => '08:00:00', 'clock_out' => '17:00:00']);

        $response = $this->actingAs($admin)->getJson("/api/admin/employees/{$employee->id}/attendance?month=2026-07");

        $response->assertOk();
        $this->assertCount(1, $response->json('records'));
        $this->assertSame('2026-07-05', substr($response->json('records.0.work_date'), 0, 10));
        $this->assertNotNull($response->json('records.0.pay'));
    }

    public function test_resolves_a_separated_employee(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();
        AttendanceRecord::factory()->for($employee)->create(['work_date' => '2026-07-05', 'clock_in' => '08:00:00', 'clock_out' => '17:00:00']);
        $employee->delete();

        $this->actingAs($admin)->getJson("/api/admin/employees/{$employee->id}/attendance?month=2026-07")
            ->assertOk()
            ->assertJsonCount(1, 'records');
    }
}
