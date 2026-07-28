<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AttendanceAdminController extends Controller {
    public function adjust(Request $request, AttendanceRecord $record) {
        $data = $request->validate([
            'clock_in' => ['required', 'date_format:H:i'],
            'clock_out' => ['required', 'date_format:H:i'],
            'reason' => ['required', 'string'],
            'details' => ['nullable', 'string'],
            'shift_start' => ['nullable', 'date_format:H:i'],
            'shift_end' => ['nullable', 'date_format:H:i'],
            'holiday_type' => ['nullable', 'in:special,regular'],
            'is_rest_day' => ['nullable', 'boolean'],
            'absence_type' => ['nullable', 'in:leave,sick_leave,half_day,absent,awol,travel'],
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
            'holiday_type' => $data['holiday_type'] ?? null,
            'is_rest_day' => $data['is_rest_day'] ?? false,
            'absence_type' => $data['absence_type'] ?? null,
            'break_out' => $data['break_out'] ?? null,
            'break_in' => $data['break_in'] ?? null,
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
        $record->update(['status' => 'approved']);

        return response()->json($record);
    }
}
