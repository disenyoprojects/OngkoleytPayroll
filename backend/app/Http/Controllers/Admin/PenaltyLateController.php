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

class PenaltyLateController extends Controller {
    public const AUTO_REASON = 'Auto-generated late penalty';

    public function __construct(private AttendancePayCalculator $calculator) {}

    /**
     * Charge the flat late penalty for every late day in the window, one row
     * per late day, so two lates read as two ₱75 rows and total ₱150 in the
     * Penalty Lates column.
     *
     * Whether a late is actually charged is still the office's call, which is
     * why the rows are ordinary adjustments: set one to 0 to excuse it, or
     * change the amount. An edited row is left alone from then on — re-running
     * only fills in late days that have no row yet, so pressing the button
     * again after excusing someone does not undo the decision.
     */
    public function generate(Request $request) {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'period' => ['required', 'in:first,second,whole'],
        ]);

        $window = PayslipPeriod::resolve($data['month'], $data['period']);
        $settings = PayrollSetting::current();
        $amount = round((float) $settings->late_penalty_amount, 2);
        $branchId = $this->branchFilter($request);

        $employees = Employee::when($branchId !== null, fn ($q) => $q->whereIn('branch_id', $branchId))->get();

        $generated = 0;
        $skipped = 0;

        foreach ($employees as $employee) {
            foreach ($this->lateDays($employee, $window, $settings) as $date) {
                // Any existing row for that day — generated or hand-entered —
                // means the day has already been decided. Never write twice.
                $exists = PayrollAdjustment::where('employee_id', $employee->id)
                    ->where('category', 'penalty_late')
                    ->whereDate('date', $date)
                    ->exists();

                if ($exists) {
                    ++$skipped;
                    continue;
                }

                PayrollAdjustment::create([
                    'employee_id' => $employee->id,
                    'date' => $date,
                    'label' => 'Penalty Late (' . \Illuminate\Support\Carbon::parse($date)->format('M j') . ')',
                    'category' => 'penalty_late',
                    'amount' => -$amount,
                    'paid' => false,
                    'reason' => self::AUTO_REASON,
                    'created_by' => $request->user()->id,
                ]);
                ++$generated;
            }
        }

        return response()->json([
            'generated' => $generated,
            'skipped' => $skipped,
            'amount' => $amount,
        ]);
    }

    /**
     * The dates in the window the employee clocked in late on. Read from the
     * pay calculator rather than the raw clock so the shift times, grace and
     * the no-pay absence rules all apply exactly as they do on the payslip.
     *
     * @return array<int, string>
     */
    private function lateDays(Employee $employee, array $window, PayrollSetting $settings): array {
        return AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('work_date', '>=', $window['from'])
            ->whereDate('work_date', '<=', $window['to'])
            ->whereNotNull('clock_out')
            ->get()
            ->filter(function (AttendanceRecord $record) use ($employee, $settings) {
                $record->setRelation('employee', $employee);
                $pay = $this->calculator->computeForRecord($record, $settings);

                return ($pay['late_minutes'] ?? 0) > 0;
            })
            ->map(fn (AttendanceRecord $record) => $record->work_date instanceof \DateTimeInterface
                ? $record->work_date->format('Y-m-d')
                : (string) $record->work_date)
            ->unique()
            ->values()
            ->all();
    }
}
