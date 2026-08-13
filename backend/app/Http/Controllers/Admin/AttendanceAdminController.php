<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AttendanceAdminController extends Controller {
    /**
     * Create a brand-new attendance record for a past date that has none —
     * for when an employee forgot to clock in/out entirely that day.
     * (Distinct from adjust(), which only corrects an existing record.)
     */
    public function store(Request $request, Employee $employee) {
        $this->assertBranchAccess($request, $employee);
        $data = $request->validate([
            'work_date' => ['required', 'date', 'before_or_equal:today'],
            'clock_in' => ['required', 'date_format:H:i'],
            'clock_out' => ['required', 'date_format:H:i'],
            'reason' => ['required', 'string'],
            'details' => ['nullable', 'string'],
            'shift_start' => ['nullable', 'date_format:H:i'],
            'shift_end' => ['nullable', 'date_format:H:i'],
            'holiday_type' => ['nullable', 'in:special,regular'],
            'is_rest_day' => ['nullable', 'boolean'],
            'absence_type' => ['nullable', 'in:leave,sick_leave,half_day,absent,awol,travel,rest_day'],
            'break_out' => ['nullable', 'date_format:H:i'],
            'break_in' => ['nullable', 'date_format:H:i'],
        ]);

        if (AttendanceRecord::where('employee_id', $employee->id)->whereDate('work_date', $data['work_date'])->exists()) {
            throw ValidationException::withMessages([
                'work_date' => ['This employee already has an attendance record for that date — use Edit Times to correct it instead.'],
            ]);
        }

        $record = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'work_date' => $data['work_date'],
            'clock_in' => $data['clock_in'],
            'clock_out' => $data['clock_out'],
            'status' => 'approved',
            'adjusted' => true,
            'reason' => $data['reason'],
            'details' => $data['details'] ?? null,
            'shift_start' => $data['shift_start'] ?? $employee->shift_start,
            'shift_end' => $data['shift_end'] ?? $employee->shift_end,
            'holiday_type' => $data['holiday_type'] ?? null,
            'is_rest_day' => (bool) ($data['is_rest_day'] ?? false),
            'absence_type' => $data['absence_type'] ?? null,
            'break_out' => $data['break_out'] ?? null,
            'break_in' => $data['break_in'] ?? null,
        ]);

        AuditLog::create([
            'type' => 'attendance',
            'employee_id' => $employee->id,
            'performed_by' => $request->user()->id,
            'action' => 'manual_entry',
            'detail' => "Manual entry added for {$data['work_date']}: {$data['clock_in']} → {$data['clock_out']}",
            'reason' => $data['reason'],
        ]);

        return response()->json($record, 201);
    }

    public function adjust(Request $request, AttendanceRecord $record) {
        $this->guardBranch($request, $record);
        $data = $request->validate([
            'clock_in' => ['required', 'date_format:H:i'],
            'clock_out' => ['required', 'date_format:H:i'],
            'reason' => ['required', 'string'],
            'details' => ['nullable', 'string'],
            'shift_start' => ['nullable', 'date_format:H:i'],
            'shift_end' => ['nullable', 'date_format:H:i'],
            'holiday_type' => ['nullable', 'in:special,regular'],
            'is_rest_day' => ['nullable', 'boolean'],
            'absence_type' => ['nullable', 'in:leave,sick_leave,half_day,absent,awol,travel,rest_day'],
            'break_out' => ['nullable', 'date_format:H:i'],
            'break_in' => ['nullable', 'date_format:H:i'],
        ]);

        $before = sprintf('%s → %s', $record->clock_in ?? '—', $record->clock_out ?? '—');

        $record->update([
            'clock_in' => $data['clock_in'],
            'clock_out' => $data['clock_out'],
            'status' => 'approved',
            'adjusted' => true,
            'reason' => $data['reason'],
            'details' => $data['details'] ?? null,
            'shift_start' => $data['shift_start'] ?? $record->shift_start,
            'shift_end' => $data['shift_end'] ?? $record->shift_end,
            'holiday_type' => array_key_exists('holiday_type', $data) ? $data['holiday_type'] : $record->holiday_type,
            'is_rest_day' => array_key_exists('is_rest_day', $data) ? (bool) $data['is_rest_day'] : $record->is_rest_day,
            'absence_type' => array_key_exists('absence_type', $data) ? $data['absence_type'] : $record->absence_type,
            'break_out' => array_key_exists('break_out', $data) ? $data['break_out'] : $record->break_out,
            'break_in' => array_key_exists('break_in', $data) ? $data['break_in'] : $record->break_in,
        ]);

        AuditLog::create([
            'type' => 'attendance',
            'employee_id' => $record->employee_id,
            'performed_by' => $request->user()->id,
            'action' => 'adjust',
            'detail' => "{$before} became {$data['clock_in']} → {$data['clock_out']}",
            'reason' => $data['reason'],
        ]);

        return response()->json($record);
    }

    public function approve(Request $request, AttendanceRecord $record) {
        $this->guardBranch($request, $record);
        $record->update(['status' => 'approved']);

        return response()->json($record);
    }

    /** Branch logins may only touch attendance for staff in their own branch. */
    private function guardBranch(Request $request, AttendanceRecord $record): void {
        $employee = Employee::withTrashed()->find($record->employee_id);
        if ($employee) {
            $this->assertBranchAccess($request, $employee);
        }
    }
}
