<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A branch login sees the totals for the branches it covers — and only those.
 */
class BranchRegisterTotalsTest extends TestCase {
    use RefreshDatabase;

    private function workedDay(Employee $employee, string $date): void {
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => $date, 'shift_start' => '08:00:00', 'shift_end' => '17:00:00',
            'clock_in' => '08:00:00', 'clock_out' => '17:00:00', 'status' => 'approved',
        ]);
    }

    public function test_a_branch_login_totals_only_its_own_branches(): void {
        $mabini = Branch::factory()->create(['name' => 'Mabini']);
        $adminOffice = Branch::factory()->create(['name' => 'Admin Office']);
        $kanto = Branch::factory()->create(['name' => 'Kanto Craving/Brew']);

        $manager = User::factory()->create(['role' => 'branch', 'branch_id' => $mabini->id]);
        $manager->branches()->sync([$mabini->id, $adminOffice->id]);

        // One worked day each at 505/day: 8h after the 1h break => 505.00.
        foreach ([$mabini, $adminOffice, $kanto] as $branch) {
            $this->workedDay(Employee::factory()->for($branch)->create(['daily_basic_rate' => 505]), '2026-08-03');
        }

        $register = $this->actingAs($manager)
            ->getJson('/api/admin/payroll/period?month=2026-08&period=first')
            ->assertOk()->json();

        // Mabini + Admin Office, not Kanto.
        $this->assertCount(2, $register['rows']);
        $this->assertEqualsWithDelta(1010.00, $register['totals']['gross'], 0.01);
        $this->assertEqualsWithDelta(1010.00, $register['totals']['net_to_release'], 0.01);
    }

    public function test_the_owner_still_totals_the_whole_company(): void {
        $owner = User::factory()->create(['role' => 'admin', 'branch_id' => null]);
        foreach ([Branch::factory()->create(), Branch::factory()->create()] as $branch) {
            $this->workedDay(Employee::factory()->for($branch)->create(['daily_basic_rate' => 505]), '2026-08-03');
        }

        $register = $this->actingAs($owner)
            ->getJson('/api/admin/payroll/period?month=2026-08&period=first')
            ->assertOk()->json();

        $this->assertEqualsWithDelta(1010.00, $register['totals']['gross'], 0.01);
    }

    public function test_the_printed_register_carries_the_total_for_a_branch_login(): void {
        $mabini = Branch::factory()->create(['name' => 'Mabini']);
        $manager = User::factory()->create(['role' => 'branch', 'branch_id' => $mabini->id]);
        $manager->branches()->sync([$mabini->id]);
        $this->workedDay(Employee::factory()->for($mabini)->create(['daily_basic_rate' => 505]), '2026-08-03');

        $response = $this->actingAs($manager)
            ->get('/api/admin/payroll/period/pdf?month=2026-08&period=first')
            ->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }
}
