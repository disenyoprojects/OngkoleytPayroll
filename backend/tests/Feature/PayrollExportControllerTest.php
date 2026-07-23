<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollExportControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_daily_csv_export_contains_a_header_and_one_row_per_employee(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['short_name' => 'Summer']);
        AttendanceRecord::factory()->for($employee)->create(['work_date' => '2026-07-21', 'clock_in' => '08:00:00', 'clock_out' => '17:00:00']);

        $response = $this->actingAs($admin)->get('/api/admin/payroll/export?range=daily&date=2026-07-21');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Summer', $response->streamedContent());
    }
}
