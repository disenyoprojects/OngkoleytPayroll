<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PayrollSetting;
use App\Models\ThirteenthMonthRecord;
use App\Services\ThirteenthMonthCalculator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class ThirteenthMonthPayslipController extends Controller {
    public function __construct(private ThirteenthMonthCalculator $calculator) {}

    public function show(Request $request, Employee $employee) {
        $year = (int) $request->query('year', now()->year);
        $settings = PayrollSetting::current();
        $record = ThirteenthMonthRecord::where('employee_id', $employee->id)->where('payroll_year', $year)->firstOrFail();
        $breakdown = $this->calculator->monthlyBreakdown($employee, $settings, $year);

        $pdf = Pdf::loadView('pdf.thirteenth-month-payslip', [
            'employee' => $employee, 'record' => $record, 'breakdown' => $breakdown,
        ]);

        return $pdf->stream("13th-month-payslip-{$employee->employee_code}-{$year}.pdf");
    }
}
