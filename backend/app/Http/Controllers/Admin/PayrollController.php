<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use App\Services\PayslipPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PayrollController extends Controller {
    public function __construct(private AttendancePayCalculator $calculator) {}

    /**
     * All-staff semi-monthly payroll register. One row per employee (Basic, OT,
     * Allowances, Deductions, Net) for a 1–15 / 16–end / whole-month window.
     * Reuses PayslipController::buildPayslip so the pay math stays single-sourced.
     */
    public function period(Request $request, PayslipController $payslips) {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'period' => ['required', 'in:first,second,whole'],
        ]);

        $window = PayslipPeriod::resolve($data['month'], $data['period']);

        $rows = Employee::withTrashed()->with('branch')->orderBy('employee_code')->get()
            ->map(function (Employee $employee) use ($payslips, $data) {
                $slip = $payslips->buildPayslip($employee, $data['month'], $data['period']);
                $t = $slip['totals'];
                return [
                    'employee_id' => $employee->id,
                    'employee_code' => $employee->employee_code,
                    'name' => $employee->short_name,
                    'full_name' => $employee->full_name,
                    'branch' => $employee->branch?->name,
                    'days' => count($slip['lines']),
                    'basic' => $t['basic'],
                    'ot' => $t['ot'],
                    'night_diff' => $t['night_diff'],
                    'gross' => $t['gross'],
                    'late_penalty' => $t['late_penalty'],
                    'allowances' => $t['adjustments'] >= 0 ? $t['adjustments'] : 0,
                    'adjustments' => $t['adjustments'],
                    'total_salary' => $t['total_salary'],
                    'paid' => $t['paid'],
                    'net_to_release' => $t['net_to_release'],
                ];
            })
            // Only employees with pay or activity in the window.
            ->filter(fn ($r) => $r['days'] > 0 || $r['adjustments'] != 0.0)
            ->values();

        return response()->json([
            'period' => $window,
            'rows' => $rows,
            'totals' => [
                'gross' => round($rows->sum('gross'), 2),
                'late_penalty' => round($rows->sum('late_penalty'), 2),
                'adjustments' => round($rows->sum('adjustments'), 2),
                'total_salary' => round($rows->sum('total_salary'), 2),
                'net_to_release' => round($rows->sum('net_to_release'), 2),
            ],
        ]);
    }

    public function daily(Request $request) {
        $date = $request->query('date', now()->toDateString());
        $settings = PayrollSetting::current();

        $rows = AttendanceRecord::with(['employee' => fn ($q) => $q->withTrashed()->with('branch')])
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

        $records = AttendanceRecord::with(['employee' => fn ($q) => $q->withTrashed()->with('branch')])
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
