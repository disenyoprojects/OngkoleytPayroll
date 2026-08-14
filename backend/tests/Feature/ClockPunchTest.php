<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClockPunchTest extends TestCase {
    use RefreshDatabase;

    private function employee(): Employee {
        return Employee::factory()->for(Branch::factory())->create([
            'shift_start' => '09:00:00', 'shift_end' => '18:00:00',
        ]);
    }

    private function punch(User $admin, Employee $employee, string $action, string $at) {
        return $this->actingAs($admin)->postJson("/api/admin/clock/{$action}", [
            'employee_id' => $employee->id,
            'clocked_at' => now()->setTimeFromTimeString($at)->toIso8601String(),
        ]);
    }

    public function test_the_six_punches_fill_their_own_columns(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();

        $this->punch($admin, $employee, 'in', '08:54')->assertOk();
        $this->punch($admin, $employee, 'break-out', '12:07')->assertOk();
        $this->punch($admin, $employee, 'break-in', '13:05')->assertOk();
        $this->punch($admin, $employee, 'out', '19:25')->assertOk();
        $this->punch($admin, $employee, 'ot-in', '19:33')->assertOk();
        $this->punch($admin, $employee, 'ot-out', '20:44')->assertOk();

        $record = AttendanceRecord::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('08:54', substr($record->clock_in, 0, 5));
        $this->assertSame('12:07', substr($record->break_out, 0, 5));
        $this->assertSame('13:05', substr($record->break_in, 0, 5));
        $this->assertSame('19:25', substr($record->clock_out, 0, 5));
        $this->assertSame('19:33', substr($record->ot_in, 0, 5));
        $this->assertSame('20:44', substr($record->ot_out, 0, 5));
    }

    public function test_a_half_day_is_two_punches_in_the_right_columns(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();

        $this->punch($admin, $employee, 'in', '09:58')->assertOk();
        $this->punch($admin, $employee, 'out', '14:01')->assertOk();

        $record = AttendanceRecord::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('14:01', substr($record->clock_out, 0, 5));
        $this->assertNull($record->break_out);
    }

    public function test_the_same_punch_cannot_be_recorded_twice(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();

        $this->punch($admin, $employee, 'in', '09:00')->assertOk();
        $this->punch($admin, $employee, 'out', '18:00')->assertOk();
        $this->punch($admin, $employee, 'out', '18:30')->assertStatus(422);
    }

    public function test_ot_in_needs_a_clock_out_first(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();

        $this->punch($admin, $employee, 'in', '09:00')->assertOk();
        $this->punch($admin, $employee, 'ot-in', '19:00')->assertStatus(422);
    }

    public function test_an_unknown_punch_is_rejected(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();

        $this->actingAs($admin)->postJson('/api/admin/clock/lunch', ['employee_id' => $employee->id])
            ->assertNotFound();
    }
}
