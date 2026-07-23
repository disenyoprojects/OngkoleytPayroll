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

        $records = $range === 'daily'
            ? AttendanceRecord::with('employee.branch')->where('work_date', $date)->whereNotNull('clock_out')->get()
            : AttendanceRecord::with('employee.branch')->whereBetween('work_date', [$date, now()->parse($date)->addDays(6)->toDateString()])->whereNotNull('clock_out')->get();

        $filename = "payroll-{$range}-{$date}.csv";

        return response()->streamDownload(function () use ($records, $settings) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Staff', 'Role', 'Branch', 'Clock In', 'Clock Out', 'Basic', 'OT', 'Night Diff', 'Total Pay', 'Status']);
            foreach ($records as $record) {
                $rate = $record->employee->daily_basic_rate === null ? null : (float) $record->employee->daily_basic_rate;
                $pay = $this->calculator->compute($record->clock_in, $record->clock_out, $settings, $rate);
                fputcsv($handle, [
                    $record->employee->short_name,
                    $record->employee->role,
                    $record->employee->branch->name,
                    $record->clock_in,
                    $record->clock_out,
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
