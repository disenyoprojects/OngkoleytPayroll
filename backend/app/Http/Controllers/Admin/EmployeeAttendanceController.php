<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use Illuminate\Http\Request;

class EmployeeAttendanceController extends Controller {
    public function __construct(private AttendancePayCalculator $calculator) {}

    public function index(Request $request, Employee $employee) {
        $this->assertBranchAccess($request, $employee);
        $month = $request->query('month', now()->format('Y-m'));
        $request->merge(['month' => $month])->validate(['month' => ['date_format:Y-m']]);
        [$year, $mon] = explode('-', $month);

        $settings = PayrollSetting::current();
        $records = AttendanceRecord::where('employee_id', $employee->id)
            ->whereYear('work_date', (int) $year)
            ->whereMonth('work_date', (int) $mon)
            ->orderBy('work_date')
            ->get()
            ->map(function (AttendanceRecord $record) use ($employee, $settings) {
                $record->setRelation('employee', $employee);
                return array_merge($record->toArray(), [
                    // work_date is a pure calendar date; serialize as Y-m-d so the
                    // Manila date isn't shifted back a day by UTC JSON serialization.
                    'work_date' => $record->work_date->format('Y-m-d'),
                    'pay' => $this->calculator->computeForRecord($record, $settings),
                ]);
            });

        return response()->json([
            'employee' => $employee->only(['id', 'employee_code', 'full_name', 'short_name', 'role']),
            'month' => $month,
            'records' => $records,
        ]);
    }
}
