<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayrollExportController extends Controller {
    public function __construct(private AttendancePayCalculator $calculator) {}

    public function export(Request $request): StreamedResponse {
        $range = $request->query('range', 'daily');
        $date = $request->query('date', now()->toDateString());
        $settings = PayrollSetting::current();

        $branchId = $this->branchFilter($request);
        $base = AttendanceRecord::with(['employee' => fn ($q) => $q->withTrashed()->with('branch')])
            ->whereNotNull('clock_out')
            ->when($branchId !== null, fn ($q) => $q->whereHas('employee', fn ($e) => $e->withTrashed()->whereIn('branch_id', $branchId)));

        // whereDate, not a plain equality: work_date is cast to a date, and an
        // equality match misses every row the moment the stored value carries a
        // time component — the export then streams a header and nothing else.
        $records = $range === 'daily'
            ? (clone $base)->whereDate('work_date', $date)->get()
            : (clone $base)->whereDate('work_date', '>=', $date)
                ->whereDate('work_date', '<=', now()->parse($date)->addDays(6)->toDateString())->get();

        $filename = "payroll-{$range}-{$date}.csv";

        return response()->streamDownload(function () use ($records, $settings) {
            $handle = fopen('php://output', 'w');
            // OT In/Out and Hours are here because without them the sheet does
            // not explain itself: a day worked on a second clock pair — staff
            // clock out at shift end, then back in to unload a delivery — showed
            // an early Clock Out beside overtime and night differential it could
            // not account for, and correct figures looked like a fault.
            fputcsv($handle, [
                'Staff', 'Role', 'Branch', 'Clock In', 'Clock Out', 'OT In', 'OT Out',
                'Hours', 'Basic', 'OT', 'Night Diff', 'Total Pay', 'Status',
            ]);
            foreach ($records as $record) {
                $pay = $this->calculator->computeForRecord($record, $settings);
                fputcsv($handle, [
                    $record->employee->short_name,
                    $record->employee->role,
                    $record->employee->branch->name,
                    $record->clock_in,
                    $record->clock_out,
                    $record->ot_in,
                    $record->ot_out,
                    // Hours actually stood, overtime included: the same figure
                    // the payslip's Hours column shows.
                    $pay['total_hours'] ?? 0,
                    $pay['basic'] ?? 0,
                    $pay['ot'] ?? 0,
                    $pay['night_diff'] ?? 0,
                    $pay['total'] ?? 0,
                    $record->status,
                ]);
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
