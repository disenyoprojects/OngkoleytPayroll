<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Days worked" counts days actually stood. A rest day, a leave or an absence
 * still carries an attendance record but earns nothing, and counting those
 * rows made the payslip contradict its own basic wage.
 */
class DaysWorkedCountTest extends TestCase {
    use RefreshDatabase;

    private function day(Employee $employee, string $date, array $extra = []): void {
        AttendanceRecord::factory()->for($employee)->create($extra + [
            'work_date' => $date, 'shift_start' => '08:00:00', 'shift_end' => '17:00:00',
            'clock_in' => '08:00:00', 'clock_out' => '17:00:00', 'status' => 'approved',
        ]);
    }

    private function slipFor(Employee $employee): array {
        return $this->actingAs(User::factory()->create())
            ->getJson("/api/admin/employees/{$employee->id}/payslip?month=2026-08&period=first")
            ->assertOk()->json();
    }

    public function test_rest_days_and_absences_are_not_counted(): void {
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => 505]);

        foreach (['2026-08-01', '2026-08-03', '2026-08-04'] as $date) {
            $this->day($employee, $date);
        }
        $this->day($employee, '2026-08-02', ['absence_type' => 'rest_day']);
        $this->day($employee, '2026-08-05', ['absence_type' => 'absent']);
        $this->day($employee, '2026-08-06', ['absence_type' => 'leave']);

        $slip = $this->slipFor($employee);

        // Six attendance rows, three of them paid.
        $this->assertCount(6, $slip['lines']);
        $this->assertEqualsWithDelta(3.0, $slip['slip']['days_worked'], 0.01);
        // And the basic wage agrees: 3 days x 505.
        $this->assertEqualsWithDelta(3 * 505, $slip['totals']['basic'], 0.01);
    }

    public function test_a_half_day_counts_as_half(): void {
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => 505]);
        $this->day($employee, '2026-08-03');
        $this->day($employee, '2026-08-04', ['absence_type' => 'half_day', 'clock_out' => '12:00:00']);

        $this->assertEqualsWithDelta(1.5, $this->slipFor($employee)['slip']['days_worked'], 0.01);
    }

    public function test_a_worked_rest_day_still_counts(): void {
        // Marked as a rest day but actually worked — it pays 130%, so it counts.
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => 505]);
        $this->day($employee, '2026-08-03');
        $this->day($employee, '2026-08-04', ['is_rest_day' => true]);

        $this->assertEqualsWithDelta(2.0, $this->slipFor($employee)['slip']['days_worked'], 0.01);
    }

    public function test_the_register_reports_the_same_count(): void {
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => 505]);
        $this->day($employee, '2026-08-03');
        $this->day($employee, '2026-08-04', ['absence_type' => 'rest_day']);

        $register = $this->actingAs(User::factory()->create())
            ->getJson('/api/admin/payroll/period?month=2026-08&period=first')
            ->assertOk()->json();

        $this->assertEqualsWithDelta(1.0, $register['rows'][0]['days'], 0.01);
    }

    public function test_an_employee_with_only_unpaid_days_still_appears_on_the_register(): void {
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => 505]);
        $this->day($employee, '2026-08-03', ['absence_type' => 'rest_day']);

        $register = $this->actingAs(User::factory()->create())
            ->getJson('/api/admin/payroll/period?month=2026-08&period=first')
            ->assertOk()->json();

        $this->assertCount(1, $register['rows']);
        $this->assertEqualsWithDelta(0.0, $register['rows'][0]['days'], 0.01);
    }
}
