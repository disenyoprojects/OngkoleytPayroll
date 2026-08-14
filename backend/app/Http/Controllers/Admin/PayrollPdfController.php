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

        $branchId = $this->branchFilter($request);
        $base = AttendanceRecord::with(['employee' => fn ($q) => $q->withTrashed()->with('branch')])
            ->whereNotNull('clock_out')
            ->when($branchId !== null, fn ($q) => $q->whereHas('employee', fn ($e) => $e->withTrashed()->whereIn('branch_id', $branchId)));

        $records = $range === 'daily'
            ? (clone $base)->where('work_date', $date)->get()
            : (clone $base)->whereBetween('work_date', [$date, now()->parse($date)->addDays(6)->toDateString()])->get();

        $rows = $records->map(fn (AttendanceRecord $record) => [
            'employee' => $record->employee,
            'pay' => $this->calculator->computeForRecord($record, $settings),
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
