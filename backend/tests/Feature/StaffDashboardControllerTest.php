<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Services\KioskTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffDashboardControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_dashboard_includes_today_week_and_thirteenth_month_sections(): void {
        $employee = Employee::factory()->for(Branch::factory())->create(['hire_date' => now()->startOfYear()]);
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => now()->toDateString(), 'clock_in' => '08:00:00', 'clock_out' => '17:00:00',
        ]);
        $token = app(KioskTokenService::class)->issue($employee);

        $response = $this->withToken($token)->getJson('/api/kiosk/dashboard');

        $response->assertOk();
        $this->assertNotNull($response->json('today'));
        $this->assertNotNull($response->json('week'));
        $this->assertArrayHasKey('thirteenth_month', $response->json());
    }
}
