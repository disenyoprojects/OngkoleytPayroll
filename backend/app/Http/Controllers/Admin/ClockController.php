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

    /**
     * What the Clock In/Out screen records: the shift pair and the overtime
     * pair. Break times are not punched here — an admin fills those in on the
     * Attendance screen. Each punch names the one before it that must exist,
     * so OT In is only possible once the shift has been clocked out.
     */
    private const PUNCHES = [
        'in' => ['column' => 'clock_in', 'after' => null, 'label' => 'Clock In'],
        'out' => ['column' => 'clock_out', 'after' => 'clock_in', 'label' => 'Clock Out'],
        'ot-in' => ['column' => 'ot_in', 'after' => 'clock_out', 'label' => 'OT In'],
        'ot-out' => ['column' => 'ot_out', 'after' => 'ot_in', 'label' => 'OT Out'],
    ];

    public function punch(Request $request, string $action) {
        if (! isset(self::PUNCHES[$action])) {
            abort(404);
        }

        $punch = self::PUNCHES[$action];
        $employee = $this->resolveEmployee($request);
        $moment = $this->resolveMoment($request);
        $record = $this->openRecordFor($employee, $moment, $punch['column']);

        if ($action === 'in') {
            if ($record) {
                throw ValidationException::withMessages(['clock_in' => ['Already clocked in today.']]);
            }

            // Stamp the shift the employee actually stands on this weekday, not
            // their default one — the day is judged for lateness against it.
            $shift = $employee->shiftFor($moment);

            return response()->json(AttendanceRecord::create([
                'employee_id' => $employee->id,
                'work_date' => $moment->toDateString(),
                'clock_in' => $moment->format('H:i:s'),
                'status' => 'pending',
                'shift_start' => $shift['start'],
                'shift_end' => $shift['end'],
            ]));
        }

        if (! $record) {
            throw ValidationException::withMessages([$punch['column'] => ['No clock-in found for today.']]);
        }

        if ($record->{$punch['column']}) {
            throw ValidationException::withMessages([$punch['column'] => ["Already recorded {$punch['label']} today."]]);
        }

        if ($punch['after'] && ! $record->{$punch['after']}) {
            throw ValidationException::withMessages([
                $punch['column'] => ["{$punch['label']} needs an earlier punch first — ask an admin to adjust the entry."],
            ]);
        }

        $record->update([$punch['column'] => $moment->format('H:i:s'), 'status' => 'pending']);

        return response()->json($record);
    }

    /**
     * The record this punch belongs to. Normally today's, but unloading can
     * run past midnight — so an OT-out with nothing open today falls back to
     * yesterday's record still waiting for one.
     */
    private function openRecordFor(Employee $employee, Carbon $moment, string $column): ?AttendanceRecord {
        $record = AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('work_date', $moment->toDateString())->first();

        if ($record || $column !== 'ot_out') {
            return $record;
        }

        return AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('work_date', $moment->copy()->subDay()->toDateString())
            ->whereNotNull('ot_in')->whereNull('ot_out')
            ->first();
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
