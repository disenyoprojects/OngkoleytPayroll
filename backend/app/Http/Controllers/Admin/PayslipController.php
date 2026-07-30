<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use App\Services\PayslipPeriod;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class PayslipController extends Controller {
    public function __construct(private AttendancePayCalculator $calculator) {}

    public function show(Request $request, Employee $employee) {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'period' => ['required', 'in:first,second,whole'],
        ]);

        return response()->json($this->buildPayslip($employee, $data['month'], $data['period']));
    }

    public function pdf(Request $request, Employee $employee) {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'period' => ['required', 'in:first,second,whole'],
        ]);

        $payslip = $this->buildPayslip($employee, $data['month'], $data['period']);
        $pdf = Pdf::loadView('pdf.payslip', ['payslip' => $payslip]);

        return $pdf->stream("payslip-{$employee->employee_code}-{$data['month']}-{$data['period']}.pdf");
    }

    public function buildPayslip(Employee $employee, string $month, string $period): array {
        $window = PayslipPeriod::resolve($month, $period);
        $settings = PayrollSetting::current();

        $records = AttendanceRecord::where('employee_id', $employee->id)
            ->whereBetween('work_date', [$window['from'], $window['to']])
            ->whereNotNull('clock_out')
            ->orderBy('work_date')
            ->get();

        $lines = [];
        $basic = $ot = $nightDiff = $latePenalty = 0.0;
        foreach ($records as $record) {
            $record->setRelation('employee', $employee);
            $pay = $this->calculator->computeForRecord($record, $settings);
            if ($pay === null) {
                continue;
            }
            $basic += (float) $pay['basic'];
            $ot += (float) $pay['ot'];
            $nightDiff += (float) $pay['night_diff'];
            $latePenalty += (float) $pay['late_penalty'];
            $lines[] = [
                'date' => $record->work_date->format('Y-m-d'),
                'shift_start' => $record->shift_start,
                'shift_end' => $record->shift_end,
                'clock_in' => $record->clock_in,
                'clock_out' => $record->clock_out,
                'hours' => $pay['total_hours'],
                'premium_label' => $pay['premium_label'],
                'late' => $pay['late'],
                'late_penalty' => $pay['late_penalty'],
                'day_pay' => $pay['total'],
            ];
        }

        $gross = round($basic + $ot + $nightDiff, 2);

        $rate = $employee->daily_basic_rate === null ? (float) $settings->daily_basic_rate : (float) $employee->daily_basic_rate;

        return [
            'employee' => $employee->only(['id', 'employee_code', 'full_name', 'short_name', 'role', 'branch_id']) + [
                'branch' => $employee->branch?->name,
                'daily_rate' => $rate,
            ],
            'period' => $window,
            'lines' => $lines,
            'totals' => [
                'basic' => round($basic, 2),
                'ot' => round($ot, 2),
                'night_diff' => round($nightDiff, 2),
                'gross' => $gross,
                'late_penalty' => round($latePenalty, 2),
                'net' => round($gross - $latePenalty, 2),
            ],
        ];
    }
}
