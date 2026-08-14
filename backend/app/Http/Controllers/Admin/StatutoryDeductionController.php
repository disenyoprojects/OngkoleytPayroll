<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\PayrollSetting;
use App\Services\PayslipPeriod;
use App\Services\PeriodEarnings;
use App\Services\SssContributionCalculator;
use Illuminate\Http\Request;

class StatutoryDeductionController extends Controller {
    private const PAGIBIG_PER_CUTOFF = 100.0;
    public const PHILHEALTH_RATE = 0.025;
    public const AUTO_REASON = 'Auto-generated statutory deduction';

    public function __construct(
        private PeriodEarnings $earnings,
        private SssContributionCalculator $sss,
    ) {}

    /**
     * Auto-generate Pag-IBIG (flat ₱100/cutoff, ₱200/whole month), PhilHealth
     * (2.5% of the period's basic wage), and SSS (bracket table, looked up on
     * the period's own net earnings — gross less tardiness/undertime — and
     * charged in full, not halved) deduction adjustments
     * for every employee in scope. Re-running is safe and is how a period is
     * corrected: a row this generator wrote earlier is updated in place when
     * the computed amount has changed, and one entered by hand is left alone.
     */
    public function generate(Request $request) {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'period' => ['required', 'in:first,second,whole'],
        ]);

        $window = PayslipPeriod::resolve($data['month'], $data['period']);
        $settings = PayrollSetting::current();
        $branchId = $this->branchFilter($request);
        $pagibigAmount = $data['period'] === 'whole' ? 200.0 : self::PAGIBIG_PER_CUTOFF;

        $employees = Employee::when($branchId !== null, fn ($q) => $q->whereIn('branch_id', $branchId))->get();

        $counts = [
            'generated' => ['pagibig' => 0, 'philhealth' => 0, 'sss' => 0],
            'updated' => ['pagibig' => 0, 'philhealth' => 0, 'sss' => 0],
            'skipped' => ['pagibig' => 0, 'philhealth' => 0, 'sss' => 0],
        ];

        foreach ($employees as $employee) {
            $outcome = $this->upsertAuto($employee, 'pagibig', $window, -$pagibigAmount, 'Pag-IBIG', $request);
            $counts[$outcome]['pagibig']++;

            $baseWage = $this->earnings->sum($employee, $window, $settings, 'base_wage');
            if ($baseWage > 0.0) {
                $philhealthAmount = -round($baseWage * self::PHILHEALTH_RATE, 2);
                $outcome = $this->upsertAuto($employee, 'philhealth', $window, $philhealthAmount, 'PhilHealth', $request);
                $counts[$outcome]['philhealth']++;
            }

            $netEarnings = $this->earnings->sum($employee, $window, $settings, 'total');
            if ($netEarnings > 0.0) {
                $sssAmount = -round($this->sss->employeeShareFor($netEarnings), 2);
                $outcome = $this->upsertAuto($employee, 'sss', $window, $sssAmount, 'SSS', $request);
                $counts[$outcome]['sss']++;
            }
        }

        return response()->json($counts);
    }

    /**
     * Writes the employee/category adjustment for this window. A row this
     * generator wrote earlier is corrected in place when the computed amount
     * has moved (a rate or rule change, say); a hand-entered row is never
     * touched. Returns 'generated', 'updated' or 'skipped'.
     */
    private function upsertAuto(Employee $employee, string $category, array $window, float $amount, string $label, Request $request): string {
        $existing = PayrollAdjustment::where('employee_id', $employee->id)->where('category', $category)
            ->whereDate('date', '>=', $window['from'])->whereDate('date', '<=', $window['to'])->first();

        if ($existing) {
            if ($existing->reason !== self::AUTO_REASON || round((float) $existing->amount, 2) === round($amount, 2)) {
                return 'skipped';
            }

            $existing->update(['amount' => $amount]);

            return 'updated';
        }

        PayrollAdjustment::create([
            'employee_id' => $employee->id, 'date' => $window['to'], 'label' => $label,
            'category' => $category, 'amount' => $amount, 'paid' => false,
            'reason' => self::AUTO_REASON, 'created_by' => $request->user()->id,
        ]);

        return 'generated';
    }
}
