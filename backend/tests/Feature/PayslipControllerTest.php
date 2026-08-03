<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayslipControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_first_half_payslip_only_includes_days_1_to_15(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create([
            'shift_start' => '08:00:00', 'shift_end' => '17:00:00', 'daily_basic_rate' => null,
        ]);
        foreach (['2026-07-02', '2026-07-10', '2026-07-20'] as $d) {
            AttendanceRecord::factory()->for($employee)->create([
                'work_date' => $d, 'shift_start' => '08:00:00', 'shift_end' => '17:00:00',
                'clock_in' => '08:00:00', 'clock_out' => '17:00:00',
            ]);
        }

        $response = $this->actingAs($admin)->getJson("/api/admin/employees/{$employee->id}/payslip?month=2026-07&period=first");

        $response->assertOk();
        $this->assertCount(2, $response->json('lines'));
        // JSON encodes 1010.0 as 1010, so compare loosely (int vs float).
        $this->assertEquals(1010.0, $response->json('totals.basic'));
        $this->assertEquals(1010.0, $response->json('totals.gross'));
        $this->assertSame('2026-07-01', $response->json('period.from'));
        $this->assertSame('2026-07-15', $response->json('period.to'));
    }

    public function test_payslip_resolves_a_separated_employee(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => null]);
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-07-03', 'clock_in' => '08:00:00', 'clock_out' => '17:00:00',
        ]);
        $employee->delete();

        $this->actingAs($admin)->getJson("/api/admin/employees/{$employee->id}/payslip?month=2026-07&period=whole")
            ->assertOk()
            ->assertJsonCount(1, 'lines');
    }

    public function test_payslip_pdf_returns_a_pdf_document(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => null]);
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-07-03', 'clock_in' => '08:00:00', 'clock_out' => '17:00:00',
        ]);

        $response = $this->actingAs($admin)->get("/api/admin/employees/{$employee->id}/payslip/pdf?month=2026-07&period=whole");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertNotEmpty($response->getContent());
    }

    public function test_payslip_breakdown_itemises_earnings_and_reconciles_net(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create([
            'shift_start' => '08:00:00', 'shift_end' => '17:00:00', 'daily_basic_rate' => null,
        ]);
        // A worked day + a special-holiday worked day (July 15).
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-07-10', 'shift_start' => '08:00:00', 'shift_end' => '17:00:00',
            'clock_in' => '08:00:00', 'clock_out' => '17:00:00',
        ]);
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-07-15', 'shift_start' => '08:00:00', 'shift_end' => '17:00:00',
            'clock_in' => '08:00:00', 'clock_out' => '17:00:00', 'holiday_type' => 'special',
        ]);

        $slip = $this->actingAs($admin)
            ->getJson("/api/admin/employees/{$employee->id}/payslip?month=2026-07&period=first")
            ->assertOk()->json();

        $labels = collect($slip['slip']['earnings'])->pluck('label');
        $this->assertTrue($labels->contains('Basic Wage'));
        $this->assertTrue($labels->contains('Special Holiday (SH)'));

        // Special-holiday premium is broken out of Basic Wage, so SH > 0.
        $sh = collect($slip['slip']['earnings'])->firstWhere('label', 'Special Holiday (SH)')['amount'];
        $this->assertGreaterThan(0, $sh);

        // The printable Net equals Net to Release.
        $this->assertEqualsWithDelta($slip['totals']['net_to_release'], $slip['slip']['net'], 0.01);
        $this->assertEquals(2, $slip['slip']['days_worked']);
    }
}
