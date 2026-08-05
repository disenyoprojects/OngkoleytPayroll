<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase {
    use RefreshDatabase;

    public function test_admin_lists_all_logins(): void {
        $admin = User::factory()->create(['role' => 'admin']);
        $branch = Branch::factory()->create(['name' => 'Mabini']);
        User::factory()->create(['role' => 'branch', 'branch_id' => $branch->id]);

        $res = $this->actingAs($admin)->getJson('/api/admin/users')->assertOk();
        $res->assertJsonCount(2);
    }

    public function test_admin_can_change_any_password(): void {
        $admin = User::factory()->create(['role' => 'admin']);
        $branch = Branch::factory()->create();
        $branchUser = User::factory()->create(['role' => 'branch', 'branch_id' => $branch->id]);

        $this->actingAs($admin)
            ->putJson("/api/admin/users/{$branchUser->id}/password", ['password' => 'newpass123'])
            ->assertOk();

        $this->assertTrue(Hash::check('newpass123', $branchUser->fresh()->password));
    }

    public function test_password_must_be_at_least_8_chars(): void {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->putJson("/api/admin/users/{$target->id}/password", ['password' => 'short'])
            ->assertStatus(422);
    }

    public function test_branch_login_cannot_manage_users(): void {
        $branch = Branch::factory()->create();
        $branchUser = User::factory()->create(['role' => 'branch', 'branch_id' => $branch->id]);
        $other = User::factory()->create();

        $this->actingAs($branchUser)->getJson('/api/admin/users')->assertStatus(403);
        $this->actingAs($branchUser)
            ->putJson("/api/admin/users/{$other->id}/password", ['password' => 'newpass123'])
            ->assertStatus(403);
    }

    public function test_user_management_requires_authentication(): void {
        $this->getJson('/api/admin/users')->assertStatus(401);
    }
}
