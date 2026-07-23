<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Services\KioskTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceClockControllerTest extends TestCase {
    use RefreshDatabase;

    private function tokenFor(Employee $employee): string {
        return app(KioskTokenService::class)->issue($employee);
    }

    public function test_clock_in_creates_a_pending_record_for_today(): void {
        $employee = Employee::factory()->for(Branch::factory())->create();

        $response = $this->withToken($this->tokenFor($employee))
            ->postJson('/api/kiosk/clock-in');

        $response->assertOk();
        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employee->id,
            'status' => 'pending',
        ]);
    }

    public function test_clock_in_twice_in_a_day_is_rejected(): void {
        $employee = Employee::factory()->for(Branch::factory())->create();
        $token = $this->tokenFor($employee);

        $this->withToken($token)->postJson('/api/kiosk/clock-in')->assertOk();
        $response = $this->withToken($token)->postJson('/api/kiosk/clock-in');

        $response->assertStatus(422);
        $this->assertSame(1, AttendanceRecord::where('employee_id', $employee->id)->count());
    }

    public function test_clock_out_fills_in_clock_out_time(): void {
        $employee = Employee::factory()->for(Branch::factory())->create();
        $token = $this->tokenFor($employee);
        $this->withToken($token)->postJson('/api/kiosk/clock-in');

        $response = $this->withToken($token)->postJson('/api/kiosk/clock-out');

        $response->assertOk();
        $record = AttendanceRecord::where('employee_id', $employee->id)->firstOrFail();
        $this->assertNotNull($record->clock_out);
    }

    public function test_clock_out_without_clocking_in_is_rejected(): void {
        $employee = Employee::factory()->for(Branch::factory())->create();

        $response = $this->withToken($this->tokenFor($employee))->postJson('/api/kiosk/clock-out');

        $response->assertStatus(422);
    }

    public function test_endpoints_reject_missing_or_invalid_kiosk_token(): void {
        $this->postJson('/api/kiosk/clock-in')->assertStatus(401);
    }
}
