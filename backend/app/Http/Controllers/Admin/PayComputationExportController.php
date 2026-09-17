<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use App\Services\PayComputationWorkbook;
use App\Services\PayslipPeriod;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The day-by-day derivation of a cutoff's earnings, as a workbook.
 *
 * The summary export answers "what is the total". This answers "how was the
 * total arrived at", which is the question actually being asked when a payslip
 * is queried — and the one nobody could answer from a screen, because the
 * second clock pair that drives overtime is not shown on any of them.
 *
 * Reads only: it computes from attendance exactly as the payslip does and
 * writes nothing back.
 */
class PayComputationExportController extends Controller {
    private const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function export(Request $request, AttendancePayCalculator $calculator): StreamedResponse {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'period' => ['required', 'in:first,second,whole'],
            'employee' => ['sometimes', 'string', 'max:64'],
        ]);

        $window = PayslipPeriod::resolve($data['month'], $data['period']);
        $settings = PayrollSetting::current();
        $branchIds = $this->branchFilter($request);

        // whereDate, not a plain equality: work_date is cast to a date, and an
        // equality match drops every row whose stored value carries a time.
        $records = AttendanceRecord::with(['employee' => fn ($q) => $q->withTrashed()->with('branch')])
            ->whereNotNull('clock_out')
            ->whereDate('work_date', '>=', $window['from'])
            ->whereDate('work_date', '<=', $window['to'])
            ->when($branchIds !== null, fn ($q) => $q->whereHas(
                'employee',
                fn ($e) => $e->withTrashed()->whereIn('branch_id', $branchIds),
            ))
            ->when(
                isset($data['employee']),
                fn ($q) => $q->whereHas(
                    'employee',
                    fn ($e) => $e->withTrashed()->where('employee_code', $data['employee']),
                ),
            )
            ->get()
            // Grouped by person, then by date: the sheet is read one employee
            // at a time and each is subtotalled, so the order is the layout.
            ->filter(fn (AttendanceRecord $record) => $record->employee !== null)
            ->sortBy([
                fn (AttendanceRecord $a, AttendanceRecord $b) => strcmp(
                    (string) $a->employee->short_name,
                    (string) $b->employee->short_name,
                ),
                fn (AttendanceRecord $a, AttendanceRecord $b) => strcmp(
                    (string) $a->work_date,
                    (string) $b->work_date,
                ),
            ])
            ->values();

        $rows = [];
        foreach ($records as $record) {
            $pay = $calculator->computeForRecord($record, $settings);
            if ($pay === null) {
                continue;
            }
            $rows[] = ['employee' => $record->employee, 'record' => $record, 'pay' => $pay];
        }

        $suffix = isset($data['employee']) ? '-' . $data['employee'] : '';
        $filename = "pay-computation-{$data['month']}-{$data['period']}{$suffix}.xlsx";

        return response()->streamDownload(function () use ($rows, $window, $settings) {
            $book = (new PayComputationWorkbook(
                $rows,
                $window,
                (float) $settings->night_diff_multiplier,
                (float) $settings->overtime_multiplier,
                (float) ($settings->minimum_overtime_minutes ?? 0),
                (float) $settings->daily_basic_rate,
            ))->build();

            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, $filename, ['Content-Type' => self::CONTENT_TYPE]);
    }
}
