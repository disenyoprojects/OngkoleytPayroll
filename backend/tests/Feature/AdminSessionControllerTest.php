<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSessionControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_admin_can_log_in_with_correct_credentials(): void {
        $admin = User::factory()->create(['password' => bcrypt('secret123')]);

        $response = $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'secret123',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_admin_login_fails_with_wrong_password(): void {
        $admin = User::factory()->create(['password' => bcrypt('secret123')]);

        $response = $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'wrong',
        ]);

        $response->assertStatus(422);
    }

    public function test_me_endpoint_requires_authentication(): void {
        $this->getJson('/api/admin/me')->assertStatus(401);
    }

    public function test_me_endpoint_returns_the_authenticated_admin(): void {
        $admin = User::factory()->create();
        $token = $admin->createToken('admin-session')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/me')
            ->assertOk()
            ->assertJsonPath('email', $admin->email);
    }
}
