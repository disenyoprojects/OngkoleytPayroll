<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PayrollAdjustmentController extends Controller {
    private const CATEGORIES = ['cash_on_hand', 'allowance', 'bonus', 'deduction', 'other'];

    public function index(Request $request, Employee $employee) {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ]);

        return response()->json(
            PayrollAdjustment::where('employee_id', $employee->id)
                ->whereBetween('date', [$data['from'], $data['to']])
                ->orderBy('date')
                ->get()
        );
    }

    public function store(Request $request, Employee $employee) {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'label' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'amount' => ['required', 'numeric'],
            'paid' => ['boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $adjustment = PayrollAdjustment::create([
            'employee_id' => $employee->id,
            'date' => $data['date'],
            'label' => $data['label'],
            'category' => $data['category'],
            'amount' => $data['amount'],
            'paid' => $data['paid'] ?? false,
            'reason' => $data['reason'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($adjustment, 201);
    }

    public function destroy(PayrollAdjustment $adjustment) {
        $adjustment->delete();

        return response()->json(['message' => 'Adjustment removed.']);
    }
}
