<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ClockController extends Controller {
    /** Active staff for the clock-in screen. */
    public function staff() {
        return response()->json(
            Employee::with('branch')
                ->orderBy('short_name')
                ->get(['id', 'short_name', 'full_name', 'role', 'branch_id'])
        );
    }

    /** Today's attendance record for one employee (or null). */
    public function status(Request $request) {
        $data = $request->validate(['employee_id' => ['required', 'exists:employees,id']]);

        $record = AttendanceRecord::where('employee_id', $data['employee_id'])
            ->where('work_date', now()->toDateString())
            ->first();

        return response()->json($record);
    }

    public function clockIn(Request $request) {
        $employee = $this->resolveEmployee($request);
        $today = now()->toDateString();

        if (AttendanceRecord::where('employee_id', $employee->id)->where('work_date', $today)->exists()) {
            throw ValidationException::withMessages(['clock_in' => ['Already clocked in today.']]);
        }

        $record = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'work_date' => $today,
            'clock_in' => now()->format('H:i:s'),
            'status' => 'pending',
            'shift_start' => $employee->shift_start,
            'shift_end' => $employee->shift_end,
        ]);

        return response()->json($record);
    }

    public function clockOut(Request $request) {
        $employee = $this->resolveEmployee($request);
        $today = now()->toDateString();

        $record = AttendanceRecord::where('employee_id', $employee->id)->where('work_date', $today)->first();

        if (! $record || $record->clock_out) {
            throw ValidationException::withMessages(['clock_out' => ['No open clock-in found for today.']]);
        }

        $record->update(['clock_out' => now()->format('H:i:s'), 'status' => 'pending']);

        return response()->json($record);
    }

    private function resolveEmployee(Request $request): Employee {
        $data = $request->validate(['employee_id' => ['required', 'exists:employees,id']]);

        return Employee::findOrFail($data['employee_id']);
    }
}
