<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_admin_with_a_hashed_password(): void
    {
        $this->artisan('admin:create', [
            '--name' => 'Jhon',
            '--email' => 'jhon@ongkoleyt.test',
            '--password' => 'secret1234',
        ])->assertSuccessful();

        $user = User::where('email', 'jhon@ongkoleyt.test')->first();

        $this->assertNotNull($user);
        $this->assertSame('Jhon', $user->name);
        $this->assertTrue(Hash::check('secret1234', $user->password));
    }

    public function test_it_rejects_a_short_password(): void
    {
        $this->artisan('admin:create', [
            '--name' => 'Jhon',
            '--email' => 'jhon@ongkoleyt.test',
            '--password' => 'short',
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'jhon@ongkoleyt.test']);
    }

    public function test_it_refuses_to_overwrite_an_existing_admin_without_force(): void
    {
        User::factory()->create(['email' => 'taken@ongkoleyt.test']);

        $this->artisan('admin:create', [
            '--name' => 'New',
            '--email' => 'taken@ongkoleyt.test',
            '--password' => 'secret1234',
        ])->assertFailed();
    }

    public function test_it_resets_an_existing_admin_password_with_force(): void
    {
        User::factory()->create([
            'email' => 'taken@ongkoleyt.test',
            'password' => Hash::make('oldpassword'),
        ]);

        $this->artisan('admin:create', [
            '--name' => 'Reset',
            '--email' => 'taken@ongkoleyt.test',
            '--password' => 'newpassword1',
            '--force' => true,
        ])->assertSuccessful();

        $user = User::where('email', 'taken@ongkoleyt.test')->first();

        $this->assertTrue(Hash::check('newpassword1', $user->password));
        $this->assertSame('Reset', $user->name);
    }
}
