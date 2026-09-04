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

    private function employee(string $shortName = 'Summer'): Employee {
        return Employee::factory()->for(Branch::factory())->create([
            'short_name' => $shortName, 'daily_basic_rate' => 505,
            'shift_start' => '09:00:00', 'shift_end' => '18:00:00',
        ]);
    }

    public function test_daily_csv_export_contains_a_header_and_one_row_per_employee(): void {
        $admin = User::factory()->create();
        $employee = $this->employee();
        AttendanceRecord::factory()->for($employee)->create(['work_date' => '2026-07-21', 'clock_in' => '08:00:00', 'clock_out' => '17:00:00']);

        $response = $this->actingAs($admin)->get('/api/admin/payroll/export?range=daily&date=2026-07-21');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Summer', $response->streamedContent());
    }

    public function test_the_header_carries_the_overtime_pair_and_hours(): void {
        $admin = User::factory()->create();
        $this->employee();

        $content = $this->actingAs($admin)
            ->get('/api/admin/payroll/export?range=daily&date=2026-07-21')->streamedContent();

        $this->assertStringContainsString('"OT In","OT Out",Hours', $content);
    }

    /**
     * The row this export exists to explain: a day worked on a second clock
     * pair. Without the OT columns it reads as someone who left at 19:45 yet
     * somehow earned night differential.
     */
    public function test_a_second_clock_pair_is_shown_with_the_hours_it_earned(): void {
        $admin = User::factory()->create();
        $employee = $this->employee('Jona');
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-08-31',
            'shift_start' => '09:00:00', 'shift_end' => '18:00:00',
            'clock_in' => '08:53:00', 'clock_out' => '19:45:00',
            'ot_in' => '21:18:00', 'ot_out' => '23:11:00',
            'holiday_type' => 'regular',
        ]);

        $row = collect(explode("\n", $this->actingAs($admin)
            ->get('/api/admin/payroll/export?range=daily&date=2026-08-31')->streamedContent()))
            ->first(fn ($line) => str_contains($line, 'Jona'));

        $this->assertStringContainsString('21:18:00', $row, 'the OT pair must be visible');
        $this->assertStringContainsString('23:11:00', $row);
        // 8h regular + 1.75h past the shift + 1.8833h on the OT pair.
        $this->assertStringContainsString('11.6333', $row, 'hours worked must be on the row');
        $this->assertStringContainsString('14.94', $row, 'the night differential it explains');
    }

    /** A plain equality on a cast date column silently matches nothing. */
    public function test_the_daily_range_finds_records_stored_with_a_time_component(): void {
        $admin = User::factory()->create();
        $employee = $this->employee('Ramon');
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-08-31 00:00:00',
            'clock_in' => '08:42:00', 'clock_out' => '23:11:00',
        ]);

        $this->assertStringContainsString('Ramon', $this->actingAs($admin)
            ->get('/api/admin/payroll/export?range=daily&date=2026-08-31')->streamedContent());
    }
}
