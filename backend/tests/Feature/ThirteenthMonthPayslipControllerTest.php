<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\ThirteenthMonthRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThirteenthMonthPayslipControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_payslip_returns_a_pdf_for_a_computed_record(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['hire_date' => '2026-01-01']);
        ThirteenthMonthRecord::factory()->for($employee)->create(['payroll_year' => 2026, 'computed_amount' => 5000, 'status' => 'computed']);

        $response = $this->actingAs($admin)->get("/api/admin/thirteenth-month/{$employee->id}/payslip?year=2026");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }
}
