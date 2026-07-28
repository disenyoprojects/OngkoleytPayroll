<?php

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AttendanceClockController extends Controller {
    public function clockIn(Request $request) {
        /** @var Employee $employee */
        $employee = $request->attributes->get('kiosk_employee');
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
        /** @var Employee $employee */
        $employee = $request->attributes->get('kiosk_employee');
        $today = now()->toDateString();

        $record = AttendanceRecord::where('employee_id', $employee->id)->where('work_date', $today)->first();

        if (! $record || $record->clock_out) {
            throw ValidationException::withMessages(['clock_out' => ['No open clock-in found for today.']]);
        }

        $record->update(['clock_out' => now()->format('H:i:s'), 'status' => 'pending']);

        return response()->json($record);
    }

    public function today(Request $request) {
        /** @var Employee $employee */
        $employee = $request->attributes->get('kiosk_employee');
        $record = AttendanceRecord::where('employee_id', $employee->id)
            ->where('work_date', now()->toDateString())
            ->first();

        return response()->json($record);
    }
}
