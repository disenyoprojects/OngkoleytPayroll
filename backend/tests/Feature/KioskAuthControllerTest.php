<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KioskAuthControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_staff_list_returns_names_without_pin_hashes(): void {
        Employee::factory()->for(Branch::factory())->create(['short_name' => 'Summer']);

        $response = $this->getJson('/api/kiosk/staff');

        $response->assertOk();
        $response->assertJsonMissingPath('0.pin_hash');
        $this->assertSame('Summer', $response->json('0.short_name'));
    }

    public function test_correct_pin_returns_a_kiosk_token(): void {
        $employee = Employee::factory()->for(Branch::factory())->create();

        $response = $this->postJson('/api/kiosk/verify-pin', [
            'employee_id' => $employee->id,
            'pin' => '1234',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_incorrect_pin_is_rejected(): void {
        $employee = Employee::factory()->for(Branch::factory())->create();

        $response = $this->postJson('/api/kiosk/verify-pin', [
            'employee_id' => $employee->id,
            'pin' => '0000',
        ]);

        $response->assertStatus(422);
    }
}
