<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PayrollController extends Controller {
    public function __construct(private AttendancePayCalculator $calculator) {}

    public function daily(Request $request) {
        $date = $request->query('date', now()->toDateString());
        $settings = PayrollSetting::current();

        $rows = AttendanceRecord::with('employee.branch')
            ->where('work_date', $date)
            ->whereNotNull('clock_out')
            ->get()
            ->map(function (AttendanceRecord $record) use ($settings) {
                $pay = $this->calculator->computeForRecord($record, $settings);
                return [
                    'employee_id' => $record->employee_id,
                    'employee' => $record->employee,
                    'record' => $record,
                    'pay' => $pay,
                ];
            });

        return response()->json([
            'rows' => $rows->values(),
            'total' => round($rows->sum(fn ($r) => $r['pay']['total']), 2),
        ]);
    }

    public function weekly(Request $request) {
        $start = Carbon::parse($request->query('start', now()->startOfWeek()->toDateString()));
        $end = $start->copy()->addDays(6);
        $settings = PayrollSetting::current();

        $records = AttendanceRecord::with('employee.branch')
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->whereNotNull('clock_out')
            ->get()
            ->groupBy('employee_id');

        $rows = $records->map(function ($employeeRecords) use ($settings) {
            $pays = $employeeRecords->map(fn (AttendanceRecord $r) => $this->calculator->computeForRecord($r, $settings));
            return [
                'employee_id' => $employeeRecords->first()->employee_id,
                'employee' => $employeeRecords->first()->employee,
                'days_worked' => $employeeRecords->count(),
                'total_hours' => round($pays->sum('total_hours'), 2),
                'basic' => round($pays->sum('basic'), 2),
                'ot' => round($pays->sum('ot'), 2),
                'total' => round($pays->sum('total'), 2),
            ];
        })->values();

        return response()->json([
            'rows' => $rows,
            'total' => round($rows->sum('total'), 2),
        ]);
    }
}
