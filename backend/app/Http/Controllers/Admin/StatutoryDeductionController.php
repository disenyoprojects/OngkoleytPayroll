<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use App\Services\PayslipPeriod;
use Illuminate\Http\Request;

class StatutoryDeductionController extends Controller {
    private const PAGIBIG_PER_CUTOFF = 100.0;
    private const PHILHEALTH_RATE = 0.025;

    public function __construct(private AttendancePayCalculator $calculator) {}

    /**
     * Auto-generate Pag-IBIG (flat ₱100/cutoff, ₱200/whole month) and
     * PhilHealth (2.5% of the period's basic wage) deduction adjustments for
     * every employee in scope, for the given payroll period. Skips an
     * employee/category pair that already has an adjustment dated in that
     * window, so this is safe to run more than once without double-charging.
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

        $generated = ['pagibig' => 0, 'philhealth' => 0];
        $skipped = ['pagibig' => 0, 'philhealth' => 0];

        foreach ($employees as $employee) {
            $hasPagibig = PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'pagibig')
                ->whereDate('date', '>=', $window['from'])->whereDate('date', '<=', $window['to'])->exists();

            if ($hasPagibig) {
                $skipped['pagibig']++;
            } else {
                PayrollAdjustment::create([
                    'employee_id' => $employee->id, 'date' => $window['to'], 'label' => 'Pag-IBIG',
                    'category' => 'pagibig', 'amount' => -$pagibigAmount, 'paid' => false,
                    'reason' => 'Auto-generated statutory deduction', 'created_by' => $request->user()->id,
                ]);
                $generated['pagibig']++;
            }

            $baseWage = AttendanceRecord::where('employee_id', $employee->id)
                ->whereDate('work_date', '>=', $window['from'])
                ->whereDate('work_date', '<=', $window['to'])
                ->whereNotNull('clock_out')
                ->get()
                ->sum(function (AttendanceRecord $record) use ($settings, $employee) {
                    $record->setRelation('employee', $employee);
                    $pay = $this->calculator->computeForRecord($record, $settings);

                    return (float) ($pay['base_wage'] ?? 0.0);
                });

            if ($baseWage <= 0.0) {
                continue;
            }

            $hasPhilhealth = PayrollAdjustment::where('employee_id', $employee->id)->where('category', 'philhealth')
                ->whereDate('date', '>=', $window['from'])->whereDate('date', '<=', $window['to'])->exists();

            if ($hasPhilhealth) {
                $skipped['philhealth']++;
                continue;
            }

            PayrollAdjustment::create([
                'employee_id' => $employee->id, 'date' => $window['to'], 'label' => 'PhilHealth',
                'category' => 'philhealth', 'amount' => -round($baseWage * self::PHILHEALTH_RATE, 2), 'paid' => false,
                'reason' => 'Auto-generated statutory deduction', 'created_by' => $request->user()->id,
            ]);
            $generated['philhealth']++;
        }

        return response()->json(['generated' => $generated, 'skipped' => $skipped]);
    }
}
