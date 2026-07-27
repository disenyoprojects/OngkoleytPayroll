<?php

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use App\Services\ThirteenthMonthCalculator;
use Illuminate\Http\Request;

class StaffDashboardController extends Controller {
    public function __construct(
        private AttendancePayCalculator $payCalculator,
        private ThirteenthMonthCalculator $thirteenthMonthCalculator,
    ) {}

    public function show(Request $request) {
        /** @var Employee $employee */
        $employee = $request->attributes->get('kiosk_employee');
        $settings = PayrollSetting::current();
        $today = now()->toDateString();
        $weekStart = now()->startOfWeek();

        $todayRecord = AttendanceRecord::where('employee_id', $employee->id)->where('work_date', $today)->first();
        $rate = $employee->daily_basic_rate === null ? null : (float) $employee->daily_basic_rate;
        $todayPay = $todayRecord ? $this->payCalculator->compute($todayRecord->clock_in, $todayRecord->clock_out, $settings, $rate, $employee->shift_start, $employee->shift_end) : null;

        $weekRecords = AttendanceRecord::where('employee_id', $employee->id)
            ->whereBetween('work_date', [$weekStart->toDateString(), $weekStart->copy()->addDays(6)->toDateString()])
            ->whereNotNull('clock_out')
            ->get();
        $weekPays = $weekRecords->map(fn (AttendanceRecord $r) => $this->payCalculator->compute($r->clock_in, $r->clock_out, $settings, $rate, $employee->shift_start, $employee->shift_end));

        $eligible = $this->thirteenthMonthCalculator->isEligible($employee, $settings, now()->year);
        $thirteenthMonth = $eligible ? [
            'total_basic_earned' => collect($this->thirteenthMonthCalculator->monthlyBreakdown($employee, $settings, now()->year))->sum('month_total_included'),
            'estimated_amount' => $this->thirteenthMonthCalculator->computedAmount($employee, $settings, now()->year),
        ] : null;

        return response()->json([
            'today' => $todayRecord ? ['record' => $todayRecord, 'pay' => $todayPay] : null,
            'week' => $weekRecords->isEmpty() ? null : [
                'days_worked' => $weekRecords->count(),
                'total_hours' => round($weekPays->sum('total_hours'), 2),
                'basic' => round($weekPays->sum('basic'), 2),
                'ot' => round($weekPays->sum('ot'), 2),
                'total' => round($weekPays->sum('total'), 2),
            ],
            'thirteenth_month' => $thirteenthMonth,
        ]);
    }
}
