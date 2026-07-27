<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class PayrollPdfController extends Controller {
    public function __construct(private AttendancePayCalculator $calculator) {}

    public function export(Request $request) {
        $range = $request->query('range', 'daily');
        $date = $request->query('date', now()->toDateString());
        $settings = PayrollSetting::current();

        $records = $range === 'daily'
            ? AttendanceRecord::with('employee.branch')->where('work_date', $date)->whereNotNull('clock_out')->get()
            : AttendanceRecord::with('employee.branch')->whereBetween('work_date', [$date, now()->parse($date)->addDays(6)->toDateString()])->whereNotNull('clock_out')->get();

        $rows = $records->map(fn (AttendanceRecord $record) => [
            'employee' => $record->employee,
            'pay' => $this->calculator->compute(
                $record->clock_in,
                $record->clock_out,
                $settings,
                $record->employee->daily_basic_rate === null ? null : (float) $record->employee->daily_basic_rate,
                $record->employee->shift_start,
                $record->employee->shift_end
            ),
        ]);

        $pdf = Pdf::loadView('pdf.payroll', [
            'range' => $range,
            'date' => $date,
            'rows' => $rows,
            'total' => round($rows->sum(fn ($r) => $r['pay']['total']), 2),
        ]);

        return $pdf->stream("payroll-{$range}-{$date}.pdf");
    }
}
