<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Employee;
use App\Services\KioskTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KioskTokenServiceTest extends TestCase {
    use RefreshDatabase;

    public function test_issued_token_resolves_back_to_the_same_employee(): void {
        $employee = Employee::factory()->for(Branch::factory())->create();
        $service = new KioskTokenService();

        $token = $service->issue($employee);
        $resolved = $service->resolve($token);

        $this->assertSame($employee->id, $resolved->id);
    }

    public function test_a_tampered_token_does_not_resolve(): void {
        $service = new KioskTokenService();

        $this->assertNull($service->resolve('not-a-real-token'));
    }

    public function test_an_expired_token_does_not_resolve(): void {
        $employee = Employee::factory()->for(Branch::factory())->create();
        $service = new KioskTokenService();

        $this->travelTo(now()->subMinutes(11));
        $token = $service->issue($employee);
        $this->travelBack();

        $this->assertNull($service->resolve($token));
    }
}
