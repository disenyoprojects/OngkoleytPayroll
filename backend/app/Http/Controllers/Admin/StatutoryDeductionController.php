<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use App\Services\PayslipPeriod;
use App\Services\SssContributionCalculator;
use Illuminate\Http\Request;

class StatutoryDeductionController extends Controller {
    private const PAGIBIG_PER_CUTOFF = 100.0;
    private const PHILHEALTH_RATE = 0.025;

    public function __construct(
        private AttendancePayCalculator $calculator,
        private SssContributionCalculator $sss,
    ) {}

    /**
     * Auto-generate Pag-IBIG (flat ₱100/cutoff, ₱200/whole month), PhilHealth
     * (2.5% of the period's basic wage), and SSS (bracket table, looked up
     * once on the combined MONTHLY net earnings then split across the two
     * cutoffs — matches how SSS is actually assessed) deduction adjustments
     * for every employee in scope. Skips an employee/category pair that
     * already has an adjustment dated in that window, so this is safe to
     * run more than once without double-charging.
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

        $employees = Employee::when($branchId, fn ($q, $bid) => $q->where('branch_id', $bid))->get();

        $generated = ['pagibig' => 0, 'philhealth' => 0, 'sss' => 0];
        $skipped = ['pagibig' => 0, 'philhealth' => 0, 'sss' => 0];

        foreach ($employees as $employee) {
            if ($this->createIfMissing($employee, 'pagibig', $window, -$pagibigAmount, 'Pag-IBIG', $request)) {
                $generated['pagibig']++;
            } else {
                $skipped['pagibig']++;
            }

            $baseWage = $this->sumOverWindow($employee, $window, $settings, 'base_wage');
            if ($baseWage > 0.0) {
                $philhealthAmount = -round($baseWage * self::PHILHEALTH_RATE, 2);
                if ($this->createIfMissing($employee, 'philhealth', $window, $philhealthAmount, 'PhilHealth', $request)) {
                    $generated['philhealth']++;
                } else {
                    $skipped['philhealth']++;
                }
            }

            $monthlyNet = $this->monthlyNetEarnings($employee, $data['month'], $data['period'], $settings);
            if ($monthlyNet > 0.0) {
                $monthlyShare = $this->sss->employeeShareFor($monthlyNet);
                $sssAmount = -round($data['period'] === 'whole' ? $monthlyShare : $monthlyShare / 2, 2);
                if ($this->createIfMissing($employee, 'sss', $window, $sssAmount, 'SSS', $request)) {
                    $generated['sss']++;
                } else {
                    $skipped['sss']++;
                }
            }
        }

        return response()->json(['generated' => $generated, 'skipped' => $skipped]);
    }

    /** Creates the adjustment unless one for this employee/category already exists in the window. Returns whether it created one. */
    private function createIfMissing(Employee $employee, string $category, array $window, float $amount, string $label, Request $request): bool {
        $exists = PayrollAdjustment::where('employee_id', $employee->id)->where('category', $category)
            ->whereDate('date', '>=', $window['from'])->whereDate('date', '<=', $window['to'])->exists();

        if ($exists) {
            return false;
        }

        PayrollAdjustment::create([
            'employee_id' => $employee->id, 'date' => $window['to'], 'label' => $label,
            'category' => $category, 'amount' => $amount, 'paid' => false,
            'reason' => 'Auto-generated statutory deduction', 'created_by' => $request->user()->id,
        ]);

        return true;
    }

    /** Sum of a per-record pay figure (e.g. base_wage) across a date window. */
    private function sumOverWindow(Employee $employee, array $window, PayrollSetting $settings, string $payKey): float {
        return (float) AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('work_date', '>=', $window['from'])
            ->whereDate('work_date', '<=', $window['to'])
            ->whereNotNull('clock_out')
            ->get()
            ->sum(function (AttendanceRecord $record) use ($settings, $employee, $payKey) {
                $record->setRelation('employee', $employee);
                $pay = $this->calculator->computeForRecord($record, $settings);

                return (float) ($pay[$payKey] ?? 0.0);
            });
    }

    /**
     * Gross earnings less tardiness/undertime for the full calendar month
     * (both cutoffs combined), regardless of which single cutoff was
     * requested — SSS is assessed once per month, not per cutoff.
     */
    private function monthlyNetEarnings(Employee $employee, string $month, string $period, PayrollSetting $settings): float {
        if ($period === 'whole') {
            return $this->sumOverWindow($employee, PayslipPeriod::resolve($month, 'whole'), $settings, 'total');
        }

        $first = $this->sumOverWindow($employee, PayslipPeriod::resolve($month, 'first'), $settings, 'total');
        $second = $this->sumOverWindow($employee, PayslipPeriod::resolve($month, 'second'), $settings, 'total');

        return $first + $second;
    }
}
