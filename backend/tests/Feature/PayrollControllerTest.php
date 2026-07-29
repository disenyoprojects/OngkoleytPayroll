<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_daily_payroll_only_includes_completed_shifts(): void {
        $admin = User::factory()->create();
        $branch = Branch::factory()->create();
        $complete = Employee::factory()->for($branch)->create();
        $incomplete = Employee::factory()->for($branch)->create();

        AttendanceRecord::factory()->for($complete)->create(['work_date' => '2026-07-21', 'clock_in' => '08:00:00', 'clock_out' => '17:00:00']);
        AttendanceRecord::factory()->for($incomplete)->create(['work_date' => '2026-07-21', 'clock_in' => '08:00:00', 'clock_out' => null]);

        $response = $this->actingAs($admin)->getJson('/api/admin/payroll/daily?date=2026-07-21');

        $response->assertOk();
        $this->assertCount(1, $response->json('rows'));
    }

    public function test_payroll_still_resolves_a_separated_employee(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['short_name' => 'Gone']);
        AttendanceRecord::factory()->for($employee)->create(['work_date' => '2026-07-21', 'clock_in' => '08:00:00', 'clock_out' => '17:00:00']);

        // The employee is later separated (soft-deleted) but still has this week's record.
        $employee->delete();

        $daily = $this->actingAs($admin)->getJson('/api/admin/payroll/daily?date=2026-07-21');
        $daily->assertOk();
        $this->assertCount(1, $daily->json('rows'));
        $this->assertSame('Gone', $daily->json('rows.0.employee.short_name'));

        $weekly = $this->actingAs($admin)->getJson('/api/admin/payroll/weekly?start=2026-07-20');
        $weekly->assertOk();
        $this->assertSame('Gone', $weekly->json('rows.0.employee.short_name'));
    }

    public function test_weekly_payroll_aggregates_seven_days(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();

        foreach (['2026-07-15', '2026-07-16', '2026-07-17'] as $date) {
            AttendanceRecord::factory()->for($employee)->create(['work_date' => $date, 'clock_in' => '08:00:00', 'clock_out' => '17:00:00']);
        }

        $response = $this->actingAs($admin)->getJson('/api/admin/payroll/weekly?start=2026-07-15');

        $response->assertOk();
        $row = collect($response->json('rows'))->firstWhere('employee_id', $employee->id);
        $this->assertSame(3, $row['days_worked']);
    }
}
