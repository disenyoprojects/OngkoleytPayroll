<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PayrollAdjustmentController extends Controller {
    public const CATEGORIES = [
        'cash_on_hand', 'allowance', 'rice_allowance', 'bonus',
        'deduction', 'penalty_late', 'cash_advance', 'sss', 'pagibig', 'philhealth', 'other',
    ];

    // Categories that always subtract, plus their default label if none is typed.
    // Penalty Late and Cash Advance are their own categories rather than
    // free-text labels so the payroll summary can column them separately.
    public const DEDUCTION_LABELS = [
        'deduction' => 'Authorized Deduction', 'penalty_late' => 'Penalty Late', 'cash_advance' => 'Cash Advance',
        'sss' => 'SSS', 'pagibig' => 'Pag-IBIG', 'philhealth' => 'PhilHealth',
    ];

    public function index(Request $request, Employee $employee) {
        $this->assertBranchAccess($request, $employee);
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
        $this->assertBranchAccess($request, $employee);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'label' => ['nullable', 'string', 'max:120'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'amount' => ['required', 'numeric'],
            'paid' => ['boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        // Sign follows the category so the admin never has to type a negative:
        // deductions (incl. SSS / Pag-IBIG / PhilHealth) always subtract;
        // allowance/bonus/cash-on-hand always add; "other" keeps the entered sign.
        $amount = match (true) {
            array_key_exists($data['category'], self::DEDUCTION_LABELS) => -abs($data['amount']),
            $data['category'] === 'other' => $data['amount'],
            default => abs($data['amount']),
        };

        // Statutory/deduction lines can be left unlabelled — fall back to the
        // category's name (SSS, Pag-IBIG, PhilHealth, Authorized Deduction).
        $label = trim((string) ($data['label'] ?? '')) ?: (self::DEDUCTION_LABELS[$data['category']] ?? 'Adjustment');

        $adjustment = PayrollAdjustment::create([
            'employee_id' => $employee->id,
            'date' => $data['date'],
            'label' => $label,
            'category' => $data['category'],
            'amount' => $amount,
            'paid' => $data['paid'] ?? false,
            'reason' => $data['reason'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($adjustment, 201);
    }

    public function destroy(Request $request, PayrollAdjustment $adjustment) {
        $adjustment->loadMissing('employee');
        if ($adjustment->employee) {
            $this->assertBranchAccess($request, $adjustment->employee);
        }
        $adjustment->delete();

        return response()->json(['message' => 'Adjustment removed.']);
    }
}
