<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\PayslipController;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A payslip is headed with the business the employee's branch belongs to.
 *
 * It used to be one hardcoded pair in four files, so Kanto Cravings staff were
 * handed slips headed WANG CHOCOLATE INC. at an address they do not work at.
 */
class PayslipCompanyHeadingTest extends TestCase {
    use RefreshDatabase;

    private const KANTO_NAME = 'KANTO CRAVINGS';
    private const KANTO_ADDRESS = 'Mabini Arcade, Lower Mabini St., Baguio City';

    private function employeeAt(Branch $branch): Employee {
        $employee = Employee::factory()->for($branch)->create([
            'daily_basic_rate' => 505, 'shift_start' => '09:00:00', 'shift_end' => '18:00:00',
        ]);
        AttendanceRecord::create([
            'employee_id' => $employee->id, 'work_date' => '2026-08-17',
            'shift_start' => '09:00:00', 'shift_end' => '18:00:00',
            'clock_in' => '09:00:00', 'clock_out' => '18:00:00', 'status' => 'approved',
        ]);

        return $employee;
    }

    private function heading(Employee $employee): array {
        return app(PayslipController::class)->buildPayslip($employee, '2026-08', 'second')['company'];
    }

    public function test_a_kanto_employee_is_headed_with_kanto(): void {
        $branch = Branch::factory()->create([
            'name' => 'Kanto Cravings',
            'company_name' => self::KANTO_NAME,
            'company_address' => self::KANTO_ADDRESS,
        ]);

        $heading = $this->heading($this->employeeAt($branch));

        $this->assertSame(self::KANTO_NAME, $heading['name']);
        $this->assertSame(self::KANTO_ADDRESS, $heading['address']);
    }

    /** Every other branch is unchanged: no heading of its own, so the default. */
    public function test_a_branch_with_no_heading_keeps_the_default(): void {
        $heading = $this->heading($this->employeeAt(Branch::factory()->create(['name' => 'Mabini'])));

        $this->assertSame(Branch::DEFAULT_COMPANY_NAME, $heading['name']);
        $this->assertSame(Branch::DEFAULT_COMPANY_ADDRESS, $heading['address']);
    }

    /** A half-filled branch still prints something sensible on both lines. */
    public function test_a_name_without_an_address_falls_back_for_the_address(): void {
        $branch = Branch::factory()->create(['name' => 'Half', 'company_name' => 'SOMETHING ELSE INC.']);

        $heading = $this->heading($this->employeeAt($branch));

        $this->assertSame('SOMETHING ELSE INC.', $heading['name']);
        $this->assertSame(Branch::DEFAULT_COMPANY_ADDRESS, $heading['address']);
    }

    /** The template the PDF is drawn from, so the heading is actually checked. */
    public function test_the_single_payslip_template_prints_the_branch_heading(): void {
        $branch = Branch::factory()->create([
            'name' => 'Kanto Cravings',
            'company_name' => self::KANTO_NAME,
            'company_address' => self::KANTO_ADDRESS,
        ]);
        $slip = app(PayslipController::class)
            ->buildPayslip($this->employeeAt($branch), '2026-08', 'second');

        $html = view('pdf.payslip', ['payslip' => $slip])->render();

        $this->assertStringContainsString(self::KANTO_NAME, $html);
        $this->assertStringContainsString(self::KANTO_ADDRESS, $html);
        $this->assertStringNotContainsString(Branch::DEFAULT_COMPANY_NAME, $html);
    }

    /**
     * A bulk print spans branches, so the heading has to move per slip rather
     * than being set once for the file — one PDF, two different headings.
     */
    public function test_a_bulk_print_heads_each_slip_with_its_own_branch(): void {
        $kanto = Branch::factory()->create([
            'name' => 'Kanto Cravings',
            'company_name' => self::KANTO_NAME,
            'company_address' => self::KANTO_ADDRESS,
        ]);
        $mabini = Branch::factory()->create(['name' => 'Mabini']);

        $slips = [
            app(PayslipController::class)->buildPayslip($this->employeeAt($kanto), '2026-08', 'second'),
            app(PayslipController::class)->buildPayslip($this->employeeAt($mabini), '2026-08', 'second'),
        ];

        $html = view('pdf.payslips-bulk', ['slips' => $slips])->render();

        $this->assertStringContainsString(self::KANTO_NAME, $html, 'the Kanto slip');
        $this->assertStringContainsString(self::KANTO_ADDRESS, $html);
        $this->assertStringContainsString(Branch::DEFAULT_COMPANY_NAME, $html, 'the Mabini slip');
        $this->assertSame(1, substr_count($html, self::KANTO_NAME), 'only the Kanto slip is headed Kanto');
    }

    public function test_the_pdf_endpoint_still_renders(): void {
        $employee = $this->employeeAt(Branch::factory()->create(['name' => 'Mabini']));

        $this->actingAs(User::factory()->create())
            ->get("/api/admin/employees/{$employee->id}/payslip/pdf?month=2026-08&period=second")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_the_command_sets_and_resets_a_branch_heading(): void {
        Branch::factory()->create(['name' => 'Kanto Cravings']);

        $this->artisan('payroll:branch-company Kanto --name="KANTO CRAVINGS" --address="Mabini Arcade, Lower Mabini St., Baguio City"')
            ->assertSuccessful();

        $branch = Branch::where('name', 'Kanto Cravings')->firstOrFail();
        $this->assertSame(self::KANTO_NAME, $branch->payslipHeading()['name']);
        $this->assertSame(self::KANTO_ADDRESS, $branch->payslipHeading()['address']);

        $this->artisan('payroll:branch-company Kanto --reset')->assertSuccessful();
        $this->assertSame(Branch::DEFAULT_COMPANY_NAME, $branch->fresh()->payslipHeading()['name']);
    }

    public function test_an_ambiguous_branch_name_is_refused(): void {
        Branch::factory()->create(['name' => 'Kanto Cravings']);
        Branch::factory()->create(['name' => 'Kanto Bodega']);

        $this->artisan('payroll:branch-company Kanto --name=X')
            ->expectsOutputToContain('matched 2 branches')
            ->assertFailed();
    }
}
