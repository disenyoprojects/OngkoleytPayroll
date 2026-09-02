<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollSetting;
use App\Models\User;
use App\Services\AttendancePayCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-weekday shift pattern: staff do not stand the same hours every day, and
 * one shift_start/shift_end on the employee could not say so.
 */
class EmployeeDayShiftTest extends TestCase {
    use RefreshDatabase;

    private function employee(): Employee {
        return Employee::factory()->for(Branch::factory())->create([
            'shift_start' => '08:00:00', 'shift_end' => '17:00:00', 'daily_basic_rate' => null,
        ]);
    }

    private function payload(Employee $employee, array $dayShifts): array {
        return [
            'employee_code' => $employee->employee_code,
            'full_name' => $employee->full_name,
            'short_name' => $employee->short_name,
            'role' => $employee->role,
            'branch_id' => $employee->branch_id,
            'employment_type' => $employee->employment_type,
            'hire_date' => $employee->hire_date->toDateString(),
            'shift_start' => '08:00',
            'shift_end' => '17:00',
            'day_shifts' => $dayShifts,
        ];
    }

    public function test_the_weekly_pattern_is_saved_and_returned(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();

        $this->actingAs($admin)->putJson("/api/admin/employees/{$employee->id}", $this->payload($employee, [
            ['day_of_week' => 1, 'shift_start' => '06:00', 'shift_end' => '15:00'],
            ['day_of_week' => 6, 'shift_start' => '11:00', 'shift_end' => '20:00'],
        ]))->assertOk();

        $this->assertSame(2, $employee->dayShifts()->count());

        $index = $this->actingAs($admin)->getJson('/api/admin/employees')->json();
        $days = collect($index)->firstWhere('id', $employee->id)['day_shifts'];
        $this->assertCount(2, $days);
        $this->assertSame('06:00:00', collect($days)->firstWhere('day_of_week', 1)['shift_start']);
    }

    /** A day cleared in the form must lose its row, not keep a stale one. */
    public function test_resending_the_pattern_without_a_day_removes_it(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();

        $this->actingAs($admin)->putJson("/api/admin/employees/{$employee->id}", $this->payload($employee, [
            ['day_of_week' => 1, 'shift_start' => '06:00', 'shift_end' => '15:00'],
            ['day_of_week' => 6, 'shift_start' => '11:00', 'shift_end' => '20:00'],
        ]))->assertOk();

        $this->actingAs($admin)->putJson("/api/admin/employees/{$employee->id}", $this->payload($employee, [
            ['day_of_week' => 1, 'shift_start' => '07:00', 'shift_end' => '16:00'],
        ]))->assertOk();

        $this->assertSame(1, $employee->dayShifts()->count());
        $this->assertSame('07:00:00', $employee->dayShifts()->first()->shift_start);
    }

    /** Omitting the key entirely is a partial update, not "clear the pattern". */
    public function test_omitting_day_shifts_leaves_the_pattern_alone(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();

        $this->actingAs($admin)->putJson("/api/admin/employees/{$employee->id}", $this->payload($employee, [
            ['day_of_week' => 1, 'shift_start' => '06:00', 'shift_end' => '15:00'],
        ]))->assertOk();

        $payload = $this->payload($employee, []);
        unset($payload['day_shifts']);
        $this->actingAs($admin)->putJson("/api/admin/employees/{$employee->id}", $payload)->assertOk();

        $this->assertSame(1, $employee->dayShifts()->count());
    }

    public function test_a_half_filled_day_is_rejected(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();

        $this->actingAs($admin)->putJson("/api/admin/employees/{$employee->id}", $this->payload($employee, [
            ['day_of_week' => 1, 'shift_start' => '06:00'],
        ]))->assertStatus(422);
    }

    public function test_shift_for_falls_back_to_the_default_on_an_unpatterned_day(): void {
        $employee = $this->employee();
        $employee->dayShifts()->create(['day_of_week' => 1, 'shift_start' => '06:00', 'shift_end' => '15:00']);

        // 2026-08-31 is a Monday, 2026-09-01 a Tuesday.
        $this->assertSame('06:00:00', $employee->shiftFor('2026-08-31')['start']);
        $this->assertSame('08:00:00', $employee->shiftFor('2026-09-01')['start']);
        $this->assertSame('08:00:00', $employee->shiftFor(null)['start']);
    }

    /** A manual entry backfills the shift for that weekday, not the default. */
    public function test_manual_entry_uses_the_weekday_shift(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();
        $employee->dayShifts()->create(['day_of_week' => 6, 'shift_start' => '11:00', 'shift_end' => '20:00']);

        // 2026-08-29 is a Saturday.
        $this->actingAs($admin)->postJson("/api/admin/employees/{$employee->id}/attendance/manual", [
            'work_date' => '2026-08-29',
            'clock_in' => '11:00',
            'clock_out' => '20:00',
            'reason' => 'Forgot to Clock In/Out',
        ])->assertCreated();

        $record = AttendanceRecord::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('11:00:00', $record->shift_start);
        $this->assertSame('20:00:00', $record->shift_end);
    }

    /**
     * End to end: the pattern decides the scheduled hours, so it decides the
     * day's pay. Without it this Saturday would be judged against the 08:00
     * default and read as three hours late and three hours of undertime.
     */
    public function test_the_weekday_shift_drives_the_pay_calculation(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();
        $employee->dayShifts()->create(['day_of_week' => 6, 'shift_start' => '11:00', 'shift_end' => '20:00']);

        // 2026-08-29 is a Saturday; worked exactly the Saturday shift.
        $this->actingAs($admin)->postJson("/api/admin/employees/{$employee->id}/attendance/manual", [
            'work_date' => '2026-08-29',
            'clock_in' => '11:00',
            'clock_out' => '20:00',
            'reason' => 'Forgot to Clock In/Out',
        ])->assertCreated();

        $record = AttendanceRecord::where('employee_id', $employee->id)->firstOrFail();
        $record->setRelation('employee', $employee);
        $pay = (new AttendancePayCalculator())->computeForRecord($record, PayrollSetting::current());

        $this->assertSame(505.00, $pay['basic']); // 8h after the 1h break
        $this->assertSame(0.0, $pay['tardiness']);
        $this->assertSame(0.0, $pay['undertime']);
    }
}
