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

    public function test_admin_can_rename_a_login(): void {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@ongkoleyt.ph']);

        $this->actingAs($admin)
            ->putJson("/api/admin/users/{$admin->id}/email", ['email' => 'owner@ongkoleyt.ph'])
            ->assertOk();

        $this->assertSame('owner@ongkoleyt.ph', $admin->fresh()->email);
    }

    public function test_renaming_to_an_address_already_in_use_is_refused(): void {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@ongkoleyt.ph']);
        User::factory()->create(['role' => 'admin', 'email' => 'owner@ongkoleyt.ph']);

        $this->actingAs($admin)
            ->putJson("/api/admin/users/{$admin->id}/email", ['email' => 'owner@ongkoleyt.ph'])
            ->assertStatus(422);

        $this->assertSame('admin@ongkoleyt.ph', $admin->fresh()->email);
    }

    public function test_admin_can_remove_a_spare_login(): void {
        $admin = User::factory()->create(['role' => 'admin']);
        $spare = User::factory()->create(['role' => 'admin', 'email' => 'admin@ongkoleyt.example']);

        $this->actingAs($admin)->deleteJson("/api/admin/users/{$spare->id}")->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $spare->id]);
    }

    public function test_you_cannot_remove_the_login_you_are_signed_in_with(): void {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'admin']); // so it isn't the last one either

        $this->actingAs($admin)->deleteJson("/api/admin/users/{$admin->id}")->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_the_last_full_access_login_cannot_be_removed(): void {
        // Signed in as a branch login would be blocked by the admin-only route,
        // so removing the sole admin can only be attempted by that admin — and
        // the self-delete guard already stops it. Guard against the case where
        // a second admin removes the other and then itself is all that remains.
        $admin = User::factory()->create(['role' => 'admin']);
        $second = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->deleteJson("/api/admin/users/{$second->id}")->assertOk();
        // Only $admin is left; it cannot remove itself, so one always survives.
        $this->actingAs($admin)->deleteJson("/api/admin/users/{$admin->id}")->assertStatus(422);

        $this->assertSame(1, User::where('role', 'admin')->count());
    }

    public function test_a_branch_login_cannot_remove_anyone(): void {
        $branch = Branch::factory()->create();
        $branchUser = User::factory()->create(['role' => 'branch', 'branch_id' => $branch->id]);
        $other = User::factory()->create(['role' => 'admin']);

        $this->actingAs($branchUser)->deleteJson("/api/admin/users/{$other->id}")->assertStatus(403);

        $this->assertDatabaseHas('users', ['id' => $other->id]);
    }
}
