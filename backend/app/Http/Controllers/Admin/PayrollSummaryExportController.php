<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\PayrollSummaryWorkbook;
use App\Services\PayslipPeriod;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The semi-monthly payroll summary as an Excel workbook, laid out the way the
 * client keeps it: a Dashboard sheet of headline totals and per-branch figures,
 * and a Payroll Summary sheet of every employee under the banded headings.
 * Eighteen columns don't fit a printed page, which is why this is a download
 * rather than another PDF.
 */
class PayrollSummaryExportController extends Controller {
    private const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function export(Request $request, PayslipController $payslips): StreamedResponse {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'period' => ['required', 'in:first,second,whole'],
        ]);

        $branchIds = $this->branchFilter($request);
        $window = PayslipPeriod::resolve($data['month'], $data['period']);

        $slips = Employee::withTrashed()->with('branch')
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->orderBy('short_name')->get()
            ->map(fn (Employee $employee) => $payslips->buildPayslip($employee, $data['month'], $data['period']))
            ->filter(fn ($slip) => count($slip['lines']) > 0 || $slip['totals']['adjustments'] != 0.0)
            ->values()
            ->all();

        $filename = "payroll-summary-{$data['month']}-{$data['period']}.xlsx";

        return response()->streamDownload(function () use ($slips, $window) {
            $book = (new PayrollSummaryWorkbook($slips, $window))->build();
            // The dashboard's bar chart only survives the write when the writer
            // is told to carry charts across.
            $writer = new Xlsx($book);
            $writer->setIncludeCharts(true);
            $writer->save('php://output');
            $book->disconnectWorksheets();
        }, $filename, ['Content-Type' => self::CONTENT_TYPE]);
    }
}
