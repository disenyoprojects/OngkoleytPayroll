<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollPdfControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_daily_pdf_export_returns_a_pdf_document(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();
        AttendanceRecord::factory()->for($employee)->create(['work_date' => '2026-07-21', 'clock_in' => '08:00:00', 'clock_out' => '17:00:00']);

        $response = $this->actingAs($admin)->get('/api/admin/payroll/pdf?range=daily&date=2026-07-21');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }
}
