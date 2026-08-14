<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ClockController extends Controller {
    /** Active staff for the clock-in screen. */
    public function staff(Request $request) {
        $branchIds = $this->branchFilter($request);

        return response()->json(
            Employee::with('branch')
                ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
                ->orderBy('short_name')
                ->get(['id', 'short_name', 'full_name', 'role', 'branch_id'])
        );
    }

    /** Today's attendance record for one employee (or null). */
    public function status(Request $request) {
        $employee = $this->resolveEmployee($request);

        $record = AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('work_date', now()->toDateString())
            ->first();

        return response()->json($record);
    }

    public function clockIn(Request $request) {
        $employee = $this->resolveEmployee($request);
        $moment = $this->resolveMoment($request);
        $workDate = $moment->toDateString();

        if (AttendanceRecord::where('employee_id', $employee->id)->whereDate('work_date', $workDate)->exists()) {
            throw ValidationException::withMessages(['clock_in' => ['Already clocked in today.']]);
        }

        $record = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'work_date' => $workDate,
            'clock_in' => $moment->format('H:i:s'),
            'status' => 'pending',
            'shift_start' => $employee->shift_start,
            'shift_end' => $employee->shift_end,
        ]);

        return response()->json($record);
    }

    public function clockOut(Request $request) {
        $employee = $this->resolveEmployee($request);
        $moment = $this->resolveMoment($request);
        $workDate = $moment->toDateString();

        $record = AttendanceRecord::where('employee_id', $employee->id)->whereDate('work_date', $workDate)->first();

        if (! $record || $record->clock_out) {
            throw ValidationException::withMessages(['clock_out' => ['No open clock-in found for today.']]);
        }

        $record->update(['clock_out' => $moment->format('H:i:s'), 'status' => 'pending']);

        return response()->json($record);
    }

    private function resolveEmployee(Request $request): Employee {
        $data = $request->validate(['employee_id' => ['required', 'exists:employees,id']]);
        $employee = Employee::findOrFail($data['employee_id']);
        $this->assertBranchAccess($request, $employee);

        return $employee;
    }

    /**
     * The moment a clock action actually happened. Offline clients queue the
     * action locally and sync it later, so they send the timestamp captured
     * on the device at the moment the button was tapped (clocked_at) rather
     * than relying on server time, which would record the sync time instead
     * of the real one. Bounded to a generous window so a stale device clock
     * or a very old queued action doesn't silently backdate a record.
     */
    private function resolveMoment(Request $request): Carbon {
        $data = $request->validate([
            'clocked_at' => ['nullable', 'date'],
        ]);

        if (empty($data['clocked_at'])) {
            return now();
        }

        // The browser sends this as an ISO string (Date.toISOString(), always
        // UTC) — convert to app-local time so the resulting wall-clock time
        // (H:i:s / work_date) reflects Manila time, not the UTC instant.
        $moment = Carbon::parse($data['clocked_at'])->setTimezone(config('app.timezone'));

        if ($moment->lt(now()->subDays(3)) || $moment->gt(now()->addMinutes(5))) {
            throw ValidationException::withMessages([
                'clocked_at' => ['That clock time is out of range. Reconnect and try again, or ask an admin to adjust the entry.'],
            ]);
        }

        return $moment;
    }
}
