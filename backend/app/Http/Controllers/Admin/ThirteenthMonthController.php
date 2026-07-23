<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollSetting;
use App\Models\ThirteenthMonthRecord;
use App\Services\ThirteenthMonthCalculator;
use Illuminate\Http\Request;

class ThirteenthMonthController extends Controller {
    public function __construct(private ThirteenthMonthCalculator $calculator) {}

    public function index(Request $request) {
        $year = (int) $request->query('year', now()->year);
        $settings = PayrollSetting::current();

        $eligible = Employee::with('branch')->get()->filter(
            fn (Employee $e) => $this->calculator->isEligible($e, $settings, $year)
        );

        $records = $eligible->map(function (Employee $employee) use ($settings, $year) {
            $record = ThirteenthMonthRecord::firstOrCreate(
                ['employee_id' => $employee->id, 'payroll_year' => $year],
                ['computed_amount' => 0, 'status' => 'pending']
            );
            return [
                'id' => $record->id,
                'employee' => $employee,
                'status' => $record->status,
                'computed_amount' => (float) $record->computed_amount,
                'manual_adjustment' => (float) $record->manual_adjustment,
                'adjusted_amount' => $record->adjusted_amount,
                'released_on' => $record->released_on,
                'payment_method' => $record->payment_method,
            ];
        })->values();

        return response()->json(['records' => $records]);
    }

    public function compute(Request $request, Employee $employee) {
        $year = (int) $request->query('year', now()->year);
        $this->computeOne($employee, $year, $request->user()->id, 'compute');

        return response()->json(['message' => 'Computed.']);
    }

    public function computeAll(Request $request) {
        $year = (int) $request->query('year', now()->year);
        $settings = PayrollSetting::current();

        $eligible = Employee::with('branch')->get()->filter(
            fn (Employee $e) => $this->calculator->isEligible($e, $settings, $year)
        );

        foreach ($eligible as $employee) {
            ThirteenthMonthRecord::firstOrCreate(
                ['employee_id' => $employee->id, 'payroll_year' => $year],
                ['computed_amount' => 0, 'status' => 'pending']
            );
        }

        $pending = ThirteenthMonthRecord::where('payroll_year', $year)->where('status', 'pending')->get();
        foreach ($pending as $record) {
            $this->computeOne($record->employee, $year, $request->user()->id, 'compute');
        }

        AuditLog::create([
            'type' => '13th_month', 'employee_id' => null, 'performed_by' => $request->user()->id,
            'action' => 'bulk_compute', 'reason' => 'Bulk computation run',
        ]);

        return response()->json(['message' => "Computed {$pending->count()} record(s)."]);
    }

    public function recompute(Request $request, Employee $employee) {
        $year = (int) $request->query('year', now()->year);
        $this->computeOne($employee, $year, $request->user()->id, 'recompute');

        return response()->json(['message' => 'Recomputed.']);
    }

    private function computeOne(Employee $employee, int $year, int $adminId, string $action): void {
        $settings = PayrollSetting::current();
        $amount = $this->calculator->computedAmount($employee, $settings, $year);

        $record = ThirteenthMonthRecord::firstOrCreate(
            ['employee_id' => $employee->id, 'payroll_year' => $year],
            ['status' => 'pending']
        );
        $oldAmount = $record->adjusted_amount;
        $record->update(['computed_amount' => $amount, 'status' => 'computed']);

        AuditLog::create([
            'type' => '13th_month', 'employee_id' => $employee->id, 'performed_by' => $adminId,
            'action' => $action, 'old_amount' => $action === 'recompute' ? $oldAmount : null,
            'new_amount' => $record->adjusted_amount,
            'reason' => $action === 'compute' ? 'Initial computation' : 'Manual recomputation triggered',
        ]);
    }
}
