<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Somebody separated mid-period still worked and was paid in it, so they appear
 * on the payroll register and in the summary workbook — both built withTrashed.
 * The employee list was not, so the payslip picker could not offer the one
 * person the sheet was asking about (Dominador C. Daos Jr., Aug 1-15 2026).
 */
class EmployeeListSeparatedTest extends TestCase {
    use RefreshDatabase;

    private function roster(): array {
        $branch = Branch::factory()->create();
        $active = Employee::factory()->for($branch)->create(['full_name' => 'Ruby Rose Anudon']);
        $gone = Employee::factory()->for($branch)->create(['full_name' => 'Dominador C. Daos Jr.']);
        $gone->delete();

        return [$active, $gone];
    }

    public function test_the_roster_still_leaves_separated_staff_out_by_default(): void {
        [$active, $gone] = $this->roster();

        $names = collect($this->actingAs(User::factory()->create())
            ->getJson('/api/admin/employees')->assertOk()->json())->pluck('full_name');

        $this->assertContains($active->full_name, $names);
        $this->assertNotContains($gone->full_name, $names);
    }

    public function test_include_separated_adds_them(): void {
        [$active, $gone] = $this->roster();

        $names = collect($this->actingAs(User::factory()->create())
            ->getJson('/api/admin/employees?include_separated=1')->assertOk()->json())->pluck('full_name');

        $this->assertContains($active->full_name, $names);
        $this->assertContains($gone->full_name, $names);
    }

    /** The picker marks them, so a leaver is not offered as though still on staff. */
    public function test_each_row_says_whether_it_is_separated(): void {
        [$active, $gone] = $this->roster();

        $rows = collect($this->actingAs(User::factory()->create())
            ->getJson('/api/admin/employees?include_separated=1')->assertOk()->json())
            ->keyBy('full_name');

        $this->assertFalse($rows[$active->full_name]['separated']);
        $this->assertTrue($rows[$gone->full_name]['separated']);
    }

    /** Their payslip was always reachable; only the listing was missing. */
    public function test_a_separated_employees_payslip_still_opens(): void {
        [, $gone] = $this->roster();

        $this->actingAs(User::factory()->create())
            ->getJson("/api/admin/employees/{$gone->id}/payslip?month=2026-08&period=first")
            ->assertOk()
            ->assertJsonPath('employee.full_name', 'Dominador C. Daos Jr.');
    }
}
