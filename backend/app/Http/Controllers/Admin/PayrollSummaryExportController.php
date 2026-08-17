<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\PayslipPeriod;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The semi-monthly payroll summary as a spreadsheet, in the column order the
 * client keeps it in: earnings first, then the authorised deductions broken
 * out one per column, then days worked, rice allowance, daily rate and the
 * net. Seventeen columns don't fit a printed page, which is why this is a
 * download rather than another PDF.
 */
class PayrollSummaryExportController extends Controller {
    private const COLUMNS = [
        'Employee', 'Branch', 'Basic', 'OT', 'NSD', 'Late', 'SH', 'RH', 'UT',
        'Total Auth. Ded.', 'Penalty Lates', 'CA etc', 'SSS', 'PhilHealth', 'Pag-IBIG',
        'Days Worked', 'Rice Allowance', 'Daily Rate', 'Gross', 'Net Pay',
    ];

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
            ->values();

        $filename = "payroll-summary-{$data['month']}-{$data['period']}.csv";

        return response()->streamDownload(function () use ($slips, $window) {
            $handle = fopen('php://output', 'w');
            // Excel needs the BOM to read the peso sign and ñ correctly.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [$window['label']]);
            fputcsv($handle, self::COLUMNS);

            $totals = array_fill_keys(self::COLUMNS, 0.0);

            foreach ($slips as $slip) {
                $row = $this->rowFor($slip);
                fputcsv($handle, $row);

                foreach (self::COLUMNS as $i => $column) {
                    if ($i >= 2 && $column !== 'Daily Rate') {
                        $totals[$column] += (float) $row[$i];
                    }
                }
            }

            $totalRow = array_map(
                fn ($column, $i) => $i === 0 ? 'TOTAL' : ($i === 1 || $column === 'Daily Rate' ? '' : number_format($totals[$column], 2, '.', '')),
                self::COLUMNS,
                array_keys(self::COLUMNS),
            );
            fputcsv($handle, $totalRow);
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** One employee's row, in COLUMNS order. Money is unformatted so the spreadsheet can total it. */
    private function rowFor(array $slip): array {
        $t = $slip['totals'];
        $money = fn (float $value) => number_format($value, 2, '.', '');

        return [
            $slip['employee']['full_name'],
            $slip['employee']['branch'] ?? '',
            $money($t['base_wage']),
            $money($t['ot']),
            $money($t['night_diff']),
            $money($t['tardiness']),
            $money($t['sh']),
            $money($t['rh']),
            $money($t['undertime']),
            $money($t['auth_deductions']),
            $money($t['penalty_late']),
            $money($t['cash_advance']),
            $money($t['sss']),
            $money($t['philhealth']),
            $money($t['pagibig']),
            number_format($slip['slip']['days_worked'], 2, '.', ''),
            $money($t['rice_allowance']),
            $money((float) $slip['employee']['daily_rate']),
            $money($t['gross']),
            $money($t['net_to_release']),
        ];
    }
}
