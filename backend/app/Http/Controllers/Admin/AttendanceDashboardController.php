<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use Illuminate\Http\Request;

class AttendanceDashboardController extends Controller {
    public function __construct(private AttendancePayCalculator $calculator) {}

    public function today(Request $request) {
        $request->validate(['date' => 'nullable|date_format:Y-m-d']);
        $date = $request->query('date', now()->toDateString());

        $settings = PayrollSetting::current();
        $records = AttendanceRecord::with(['employee' => fn ($q) => $q->withTrashed()->with('branch')])
            ->where('work_date', $date)
            ->get();

        $rows = $records->map(function (AttendanceRecord $record) use ($settings) {
            $pay = $this->calculator->computeForRecord($record, $settings);
            return [
                'record' => $record,
                'employee' => $record->employee,
                'pay' => $pay,
            ];
        });

        return response()->json([
            'date' => $date,
            'clocked_in' => $records->count(),
            'pending' => $records->where('status', 'pending')->count(),
            'approved' => $records->where('status', 'approved')->count(),
            'total_pay_today' => round($rows->sum(fn ($r) => $r['pay']['total'] ?? 0), 2),
            'rows' => $rows->values(),
        ]);
    }
}
