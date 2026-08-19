<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use App\Services\PayslipPeriod;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class PayslipController extends Controller {
    public function __construct(private AttendancePayCalculator $calculator) {}

    public function show(Request $request, Employee $employee) {
        $this->assertBranchAccess($request, $employee);
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'period' => ['required', 'in:first,second,whole'],
        ]);

        return response()->json($this->buildPayslip($employee, $data['month'], $data['period']));
    }

    public function pdf(Request $request, Employee $employee) {
        $this->assertBranchAccess($request, $employee);
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
            ->whereDate('work_date', '>=', $window['from'])
            ->whereDate('work_date', '<=', $window['to'])
            ->whereNotNull('clock_out')
            ->orderBy('work_date')
            ->get();

        $lines = [];
        $basic = $ot = $nightDiff = $tardiness = $undertime = $daysWorked = 0.0;
        // Split the day's regular pay into ordinary wage + holiday/rest premiums
        // so the payslip can itemise Basic Wage, Special Holiday (SH), etc.
        $baseWage = $sh = $rh = $restPremium = 0.0;
        foreach ($records as $record) {
            $record->setRelation('employee', $employee);
            $pay = $this->calculator->computeForRecord($record, $settings);
            if ($pay === null) {
                continue;
            }
            $basic += (float) $pay['basic'];
            $ot += (float) $pay['ot'];
            $nightDiff += (float) $pay['night_diff'];
            $tardiness += (float) $pay['tardiness'];
            $undertime += (float) $pay['undertime'];

            // Days worked counts days actually stood, not attendance rows: an
            // unworked rest day, a leave or an absence still carries a record
            // but earns nothing, and a half day is half a day.
            $daysWorked += match (true) {
                in_array($record->absence_type, AttendancePayCalculator::NO_PAY_ABSENCES, true) => 0.0,
                $record->absence_type === 'half_day' => 0.5,
                default => 1.0,
            };

            $baseWage += (float) $pay['base_wage'];
            $uplift = (float) $pay['basic'] - (float) $pay['base_wage']; // holiday/rest premium portion
            $label = (string) $pay['premium_label'];
            if (str_contains($label, 'Regular Holiday')) {
                $rh += $uplift;
            } elseif (str_contains($label, 'Special Holiday')) {
                $sh += $uplift;
            } elseif (str_contains($label, 'Rest Day')) {
                $restPremium += $uplift;
            }
            $lines[] = [
                'date' => $record->work_date->format('Y-m-d'),
                'shift_start' => $record->shift_start,
                'shift_end' => $record->shift_end,
                'clock_in' => $record->clock_in,
                'clock_out' => $record->clock_out,
                'hours' => $pay['total_hours'],
                'premium_label' => $pay['premium_label'],
                'late' => $pay['late'],
                'late_minutes' => $pay['late_minutes'],
                'tardiness' => $pay['tardiness'],
                'undertime_minutes' => $pay['undertime_minutes'],
                'undertime' => $pay['undertime'],
                'day_pay' => $pay['total'],
            ];
        }

        $gross = round($basic + $ot + $nightDiff, 2);

        // Ad-hoc adjustments (bonuses, allowances, cash advances) dated inside this window.
        $adjustments = PayrollAdjustment::where('employee_id', $employee->id)
            ->whereDate('date', '>=', $window['from'])
            ->whereDate('date', '<=', $window['to'])
            ->orderBy('date')
            ->get()
            ->map(fn (PayrollAdjustment $a) => [
                'id' => $a->id,
                'date' => $a->date->format('Y-m-d'),
                'label' => $a->label,
                'category' => $a->category,
                'amount' => (float) $a->amount,
                'paid' => $a->paid,
            ])->values();

        $adjustmentsTotal = round($adjustments->sum('amount'), 2);
        $paidTotal = round($adjustments->where('paid', true)->sum('amount'), 2);

        // Rice Allowance and Penalty Lates get their own summary columns, but
        // they're easy to enter under the generic Allowance / Authorized
        // Deduction type with only the label saying what they really are. Read
        // the label as a fallback so those columns fill themselves instead of
        // the amount hiding inside a lump sum.
        $labelFallbacks = [
            'allowance' => ['rice_allowance', '/\brice\b/i'],
            'deduction' => ['penalty_late', '/\blate|\bpenalt/i'],
        ];
        // Each adjustment lands in exactly one bucket, so re-reading the label
        // moves an amount between columns rather than counting it twice.
        $bucket = function (array $a) use ($labelFallbacks): string {
            [$target, $pattern] = $labelFallbacks[$a['category']] ?? [null, null];

            return $target !== null && preg_match($pattern, (string) $a['label']) ? $target : $a['category'];
        };

        // Per-bucket sums, so the payroll summary can give each its own column
        // instead of collapsing everything into one figure. Deductions are
        // reported positive — the sign is in the column heading.
        $byCategory = fn (string $category) => round(abs(
            $adjustments->filter(fn (array $a) => $bucket($a) === $category)->sum('amount')
        ), 2);
        $penaltyLate = $byCategory('penalty_late');
        $cashAdvance = $byCategory('cash_advance');
        $otherAuthorised = $byCategory('deduction');
        $totalSalary = round($gross - $tardiness - $undertime + $adjustmentsTotal, 2);
        $netToRelease = round($totalSalary - $paidTotal, 2);

        // Itemised earnings / deductions for the printable payslip. Net here
        // equals Net to Release: paid-in-cash amounts show as both an earning
        // and a deduction so the take-home is what's actually handed over.
        $earnings = [
            ['label' => 'Basic Wage', 'amount' => round($baseWage, 2)],
            ['label' => 'Overtime Pay', 'amount' => round($ot, 2)],
            ['label' => 'Night Shift Differential', 'amount' => round($nightDiff, 2)],
            ['label' => 'Special Holiday (SH)', 'amount' => round($sh, 2)],
            ['label' => 'Regular Holiday (RH)', 'amount' => round($rh, 2)],
        ];
        if ($restPremium > 0.005) {
            $earnings[] = ['label' => 'Rest Day Premium', 'amount' => round($restPremium, 2)];
        }
        foreach ($adjustments->where('amount', '>', 0) as $a) {
            $earnings[] = ['label' => $a['label'], 'amount' => round((float) $a['amount'], 2)];
        }

        $deductions = [
            ['label' => 'Tardiness', 'amount' => round($tardiness, 2)],
            ['label' => 'Undertime/Overbreak', 'amount' => round($undertime, 2)],
        ];
        foreach ($adjustments->where('amount', '<', 0) as $a) {
            $deductions[] = ['label' => $a['label'], 'amount' => round(abs((float) $a['amount']), 2)];
        }
        foreach ($adjustments->filter(fn ($a) => $a['amount'] > 0 && $a['paid']) as $a) {
            $deductions[] = ['label' => $a['label'] . ' (paid in cash)', 'amount' => round((float) $a['amount'], 2)];
        }

        $grossEarnings = round(collect($earnings)->sum('amount'), 2);
        $totalDeductions = round(collect($deductions)->sum('amount'), 2);

        $rate = $employee->daily_basic_rate === null ? (float) $settings->daily_basic_rate : (float) $employee->daily_basic_rate;

        return [
            'employee' => $employee->only(['id', 'employee_code', 'full_name', 'short_name', 'role', 'branch_id']) + [
                'branch' => $employee->branch?->name,
                'daily_rate' => $rate,
            ],
            'period' => $window,
            'lines' => $lines,
            'adjustments' => $adjustments,
            'slip' => [
                'days_worked' => round($daysWorked, 2),
                'earnings' => $earnings,
                'deductions' => $deductions,
                'gross_earnings' => $grossEarnings,
                'total_deductions' => $totalDeductions,
                'net' => round($grossEarnings - $totalDeductions, 2),
            ],
            'totals' => [
                'basic' => round($basic, 2),
                'ot' => round($ot, 2),
                'night_diff' => round($nightDiff, 2),
                'gross' => $gross,
                'tardiness' => round($tardiness, 2),
                'undertime' => round($undertime, 2),
                'base_wage' => round($baseWage, 2),
                'sh' => round($sh, 2),
                'rh' => round($rh, 2),
                'rest_premium' => round($restPremium, 2),
                'rice_allowance' => $byCategory('rice_allowance'),
                'penalty_late' => $penaltyLate,
                'cash_advance' => $cashAdvance,
                'other_authorised' => $otherAuthorised,
                'auth_deductions' => round($penaltyLate + $cashAdvance + $otherAuthorised, 2),
                'sss' => $byCategory('sss'),
                'philhealth' => $byCategory('philhealth'),
                'pagibig' => $byCategory('pagibig'),
                'adjustments' => $adjustmentsTotal,
                'total_salary' => $totalSalary,
                'paid' => $paidTotal,
                'net_to_release' => $netToRelease,
            ],
        ];
    }
}
