<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Mabini / Diego / Bonifacio sites share one login, while Kanto and
 * Bodega keep their own and the owner sees everything.
 */
class MultiBranchLoginTest extends TestCase {
    use RefreshDatabase;

    public function test_a_login_covering_three_branches_sees_all_three(): void {
        $mabini = Branch::factory()->create(['name' => 'Mabini']);
        $diego = Branch::factory()->create(['name' => 'Diego']);
        $bonifacio = Branch::factory()->create(['name' => 'Bonifacio']);
        $kanto = Branch::factory()->create(['name' => 'Kanto']);

        $manager = User::factory()->create(['role' => 'branch', 'branch_id' => $mabini->id]);
        $manager->branches()->sync([$mabini->id, $diego->id, $bonifacio->id]);

        foreach ([$mabini, $diego, $bonifacio, $kanto] as $branch) {
            Employee::factory()->for($branch)->create();
        }

        $response = $this->actingAs($manager)->getJson('/api/admin/employees')->assertOk();

        $this->assertCount(3, $response->json());
        $this->assertEqualsCanonicalizing(
            [$mabini->id, $diego->id, $bonifacio->id],
            array_column($response->json(), 'branch_id'),
        );
    }

    public function test_a_single_branch_login_still_sees_only_its_own(): void {
        $kanto = Branch::factory()->create(['name' => 'Kanto']);
        $bodega = Branch::factory()->create(['name' => 'Bodega']);
        // No pivot rows at all — the branch_id column alone must still scope it.
        $manager = User::factory()->create(['role' => 'branch', 'branch_id' => $kanto->id]);
        Employee::factory()->for($kanto)->create();
        Employee::factory()->for($bodega)->create();

        $response = $this->actingAs($manager)->getJson('/api/admin/employees')->assertOk();

        $this->assertCount(1, $response->json());
        $this->assertSame($kanto->id, $response->json('0.branch_id'));
    }

    public function test_the_owner_sees_every_branch(): void {
        $owner = User::factory()->create(['role' => 'admin', 'branch_id' => null]);
        Employee::factory()->for(Branch::factory())->create();
        Employee::factory()->for(Branch::factory())->create();

        $this->actingAs($owner)->getJson('/api/admin/employees')->assertOk()->assertJsonCount(2);
    }

    public function test_the_branch_list_is_limited_to_the_covered_branches(): void {
        $mabini = Branch::factory()->create(['name' => 'Mabini']);
        $diego = Branch::factory()->create(['name' => 'Diego']);
        Branch::factory()->create(['name' => 'Kanto']);
        $manager = User::factory()->create(['role' => 'branch', 'branch_id' => $mabini->id]);
        $manager->branches()->sync([$mabini->id, $diego->id]);

        $response = $this->actingAs($manager)->getJson('/api/admin/branches')->assertOk();

        $this->assertEqualsCanonicalizing(['Diego', 'Mabini'], array_column($response->json(), 'name'));
    }

    public function test_an_employee_outside_the_covered_branches_is_forbidden(): void {
        $mabini = Branch::factory()->create(['name' => 'Mabini']);
        $kanto = Branch::factory()->create(['name' => 'Kanto']);
        $manager = User::factory()->create(['role' => 'branch', 'branch_id' => $mabini->id]);
        $manager->branches()->sync([$mabini->id]);
        $outsider = Employee::factory()->for($kanto)->create();

        $this->actingAs($manager)
            ->getJson("/api/admin/employees/{$outsider->id}/payslip?month=2026-08&period=first")
            ->assertForbidden();
    }

    public function test_a_new_employee_is_pinned_to_a_covered_branch(): void {
        $mabini = Branch::factory()->create(['name' => 'Mabini']);
        $diego = Branch::factory()->create(['name' => 'Diego']);
        $kanto = Branch::factory()->create(['name' => 'Kanto']);
        $manager = User::factory()->create(['role' => 'branch', 'branch_id' => $mabini->id]);
        $manager->branches()->sync([$mabini->id, $diego->id]);

        // Diego is in scope, so the submitted choice stands.
        $this->actingAs($manager)->postJson('/api/admin/employees', [
            'employee_code' => 'ONG-9001', 'full_name' => 'In Scope', 'short_name' => 'Scope',
            'role' => 'Staff', 'branch_id' => $diego->id, 'employment_type' => 'regular', 'hire_date' => '2026-01-05',
        ])->assertCreated();
        $this->assertSame($diego->id, Employee::where('employee_code', 'ONG-9001')->firstOrFail()->branch_id);

        // Kanto is not, so it falls back to the login's primary branch.
        $this->actingAs($manager)->postJson('/api/admin/employees', [
            'employee_code' => 'ONG-9002', 'full_name' => 'Out Of Scope', 'short_name' => 'Out',
            'role' => 'Staff', 'branch_id' => $kanto->id, 'employment_type' => 'regular', 'hire_date' => '2026-01-05',
        ])->assertCreated();
        $this->assertSame($mabini->id, Employee::where('employee_code', 'ONG-9002')->firstOrFail()->branch_id);
    }

    public function test_me_reports_every_covered_branch(): void {
        $mabini = Branch::factory()->create(['name' => 'Mabini']);
        $diego = Branch::factory()->create(['name' => 'Diego']);
        $manager = User::factory()->create(['role' => 'branch', 'branch_id' => $mabini->id]);
        $manager->branches()->sync([$mabini->id, $diego->id]);

        $this->actingAs($manager)->getJson('/api/admin/me')->assertOk()
            ->assertJsonPath('role', 'branch')
            ->assertJsonCount(2, 'branches');
    }
}
