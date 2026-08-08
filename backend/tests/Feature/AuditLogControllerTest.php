<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_returns_entries_newest_first(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();
        $older = AuditLog::create(['type' => 'attendance', 'employee_id' => $employee->id, 'performed_by' => $admin->id, 'action' => 'adjust', 'reason' => 'x']);
        sleep(1);
        $newer = AuditLog::create(['type' => '13th_month', 'employee_id' => $employee->id, 'performed_by' => $admin->id, 'action' => 'lock', 'reason' => 'y']);

        $response = $this->actingAs($admin)->getJson('/api/admin/audit-log');

        $response->assertOk();
        $this->assertSame($newer->id, $response->json('0.id'));
        $this->assertSame($older->id, $response->json('1.id'));
    }

    public function test_filters_by_type_and_employee_id(): void {
        $admin = User::factory()->create();
        $employeeA = Employee::factory()->for(Branch::factory())->create();
        $employeeB = Employee::factory()->for(Branch::factory())->create();
        AuditLog::create(['type' => 'attendance', 'employee_id' => $employeeA->id, 'performed_by' => $admin->id, 'action' => 'adjust', 'reason' => 'x']);
        $match = AuditLog::create(['type' => '13th_month', 'employee_id' => $employeeA->id, 'performed_by' => $admin->id, 'action' => 'lock', 'reason' => 'y']);
        AuditLog::create(['type' => '13th_month', 'employee_id' => $employeeB->id, 'performed_by' => $admin->id, 'action' => 'lock', 'reason' => 'z']);

        $response = $this->actingAs($admin)->getJson("/api/admin/audit-log?type=13th_month&employee_id={$employeeA->id}");

        $response->assertOk()->assertJsonCount(1);
        $this->assertSame($match->id, $response->json('0.id'));
    }
}
