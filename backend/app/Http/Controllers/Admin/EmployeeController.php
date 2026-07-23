<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller {
    public function index() {
        return response()->json(
            Employee::with('branch')->orderBy('short_name')->get()
        );
    }

    public function branches() {
        return response()->json(
            Branch::orderBy('name')->get(['id', 'name'])
        );
    }

    public function store(Request $request) {
        $data = $request->validate([
            'employee_code' => ['required', 'string', 'unique:employees,employee_code'],
            'full_name' => ['required', 'string'],
            'short_name' => ['required', 'string'],
            'role' => ['required', 'string'],
            'branch_id' => ['required', 'exists:branches,id'],
            'employment_type' => ['required', Rule::in(['regular', 'probationary', 'fixed_term', 'seasonal'])],
            'hire_date' => ['required', 'date'],
            'resignation_date' => ['nullable', 'date'],
            'pin' => ['required', 'string', 'size:4'],
            'daily_basic_rate' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string'],
        ]);

        if (array_key_exists('daily_basic_rate', $data) && $data['daily_basic_rate'] !== null
            && empty(trim((string) ($data['reason'] ?? '')))) {
            return response()->json([
                'message' => 'A reason is required when setting a daily basic rate.',
                'errors' => ['reason' => ['A reason is required when setting a daily basic rate.']],
            ], 422);
        }

        $employee = new Employee([
            'employee_code' => $data['employee_code'],
            'full_name' => $data['full_name'],
            'short_name' => $data['short_name'],
            'role' => $data['role'],
            'branch_id' => $data['branch_id'],
            'employment_type' => $data['employment_type'],
            'hire_date' => $data['hire_date'],
            'resignation_date' => $data['resignation_date'] ?? null,
            'daily_basic_rate' => $data['daily_basic_rate'] ?? null,
        ]);
        $employee->pin = $data['pin'];
        $employee->save();

        if (($data['daily_basic_rate'] ?? null) !== null) {
            AuditLog::create([
                'type' => 'employee',
                'employee_id' => $employee->id,
                'performed_by' => $request->user()->id,
                'action' => 'rate_override_set',
                'detail' => "Daily basic rate set to {$data['daily_basic_rate']} on create",
                'new_amount' => $data['daily_basic_rate'],
                'reason' => $data['reason'],
            ]);
        }

        return response()->json($employee->load('branch'), 201);
    }
}
