<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Services\KioskTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KioskShiftStampTest extends TestCase {
    use RefreshDatabase;

    private function tokenFor(Employee $employee): string {
        return app(KioskTokenService::class)->issue($employee);
    }

    public function test_clock_in_stamps_employee_shift_on_record(): void {
        $employee = Employee::factory()->for(Branch::factory())->create([
            'shift_start' => '14:00:00', 'shift_end' => '23:00:00',
        ]);

        $response = $this->withToken($this->tokenFor($employee))
            ->postJson('/api/kiosk/clock-in');

        $response->assertOk();
        $record = AttendanceRecord::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('14:00:00', $record->shift_start);
        $this->assertSame('23:00:00', $record->shift_end);
    }

    public function test_clock_out_does_not_overwrite_an_adjusted_shift(): void {
        $employee = Employee::factory()->for(Branch::factory())->create([
            'shift_start' => '14:00:00', 'shift_end' => '23:00:00',
        ]);
        $token = $this->tokenFor($employee);
        $this->withToken($token)->postJson('/api/kiosk/clock-in')->assertOk();

        $record = AttendanceRecord::where('employee_id', $employee->id)->firstOrFail();
        $record->update(['shift_start' => '09:00:00', 'shift_end' => '18:00:00']);

        $this->withToken($token)->postJson('/api/kiosk/clock-out')->assertOk();

        $record->refresh();
        $this->assertSame('09:00:00', $record->shift_start);
        $this->assertSame('18:00:00', $record->shift_end);
    }
}
