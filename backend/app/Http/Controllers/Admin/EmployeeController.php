<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller {
    public function index(Request $request) {
        $branchIds = $this->branchFilter($request);

        return response()->json(
            Employee::with('branch')
                ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
                ->orderBy('short_name')->get()
        );
    }

    public function branches(Request $request) {
        $branchIds = $this->branchFilter($request);

        return response()->json(
            Branch::when($branchIds !== null, fn ($q) => $q->whereIn('id', $branchIds))
                ->orderBy('name')->get(['id', 'name'])
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
            'shift_start' => ['nullable', 'date_format:H:i'],
            'shift_end' => ['nullable', 'date_format:H:i'],
            'daily_basic_rate' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string'],
        ]);

        // A branch login can only add staff to a branch it covers, regardless
        // of what branch_id was submitted.
        $data['branch_id'] = $this->confineBranch($request, $data['branch_id'] ?? null);

        return DB::transaction(function () use ($request, $data) {
            $employee = new Employee([
                'employee_code' => $data['employee_code'],
                'full_name' => $data['full_name'],
                'short_name' => $data['short_name'],
                'role' => $data['role'],
                'branch_id' => $data['branch_id'],
                'employment_type' => $data['employment_type'],
                'hire_date' => $data['hire_date'],
                'resignation_date' => $data['resignation_date'] ?? null,
                'shift_start' => $data['shift_start'] ?? '08:00',
                'shift_end' => $data['shift_end'] ?? '17:00',
                'daily_basic_rate' => $data['daily_basic_rate'] ?? null,
            ]);
            $employee->save();

            if (($data['daily_basic_rate'] ?? null) !== null) {
                AuditLog::create([
                    'type' => 'employee',
                    'employee_id' => $employee->id,
                    'performed_by' => $request->user()->id,
                    'action' => 'rate_override_set',
                    'detail' => "Daily basic rate set to {$data['daily_basic_rate']} on create",
                    'new_amount' => $data['daily_basic_rate'],
                    'reason' => isset($data['reason']) ? trim($data['reason']) : null,
                ]);
            }

            return response()->json($employee->load('branch'), 201);
        });
    }

    public function update(Request $request, Employee $employee) {
        $this->assertBranchAccess($request, $employee);

        $data = $request->validate([
            'employee_code' => ['required', 'string', Rule::unique('employees', 'employee_code')->ignore($employee->id)],
            'full_name' => ['required', 'string'],
            'short_name' => ['required', 'string'],
            'role' => ['required', 'string'],
            'branch_id' => ['required', 'exists:branches,id'],
            'employment_type' => ['required', Rule::in(['regular', 'probationary', 'fixed_term', 'seasonal'])],
            'hire_date' => ['required', 'date'],
            'resignation_date' => ['nullable', 'date'],
            'shift_start' => ['nullable', 'date_format:H:i'],
            'shift_end' => ['nullable', 'date_format:H:i'],
            'daily_basic_rate' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string'],
        ]);

        // A branch login can't move an employee outside the branches it covers,
        // regardless of what branch_id was submitted.
        if (array_key_exists('branch_id', $data)) {
            $data['branch_id'] = $this->confineBranch($request, $data['branch_id']);
        }

        $oldRate = $employee->daily_basic_rate === null ? null : (float) $employee->daily_basic_rate;
        if (array_key_exists('daily_basic_rate', $data)) {
            $newRate = $data['daily_basic_rate'] === null ? null : (float) $data['daily_basic_rate'];
        } else {
            $newRate = $oldRate; // key omitted → preserve existing rate; not a change
        }
        $rateChanged = $oldRate !== $newRate;

        return DB::transaction(function () use ($request, $employee, $data, $oldRate, $newRate, $rateChanged) {
            $employee->fill([
                'employee_code' => $data['employee_code'],
                'full_name' => $data['full_name'],
                'short_name' => $data['short_name'],
                'role' => $data['role'],
                'branch_id' => $data['branch_id'],
                'employment_type' => $data['employment_type'],
                'hire_date' => $data['hire_date'],
                'resignation_date' => $data['resignation_date'] ?? null,
                'shift_start' => $data['shift_start'] ?? $employee->shift_start,
                'shift_end' => $data['shift_end'] ?? $employee->shift_end,
                'daily_basic_rate' => $newRate,
            ]);
            $employee->save();

            if ($rateChanged) {
                AuditLog::create([
                    'type' => 'employee',
                    'employee_id' => $employee->id,
                    'performed_by' => $request->user()->id,
                    'action' => 'rate_override_changed',
                    'detail' => sprintf('Daily basic rate changed from %s to %s',
                        $oldRate === null ? 'global' : number_format($oldRate, 2),
                        $newRate === null ? 'global' : number_format($newRate, 2)),
                    'old_amount' => $oldRate,
                    'new_amount' => $newRate,
                    'reason' => isset($data['reason']) ? trim($data['reason']) : null,
                ]);
            }

            return response()->json($employee->load('branch'));
        });
    }

    public function separated(Request $request) {
        $branchIds = $this->branchFilter($request);
        $query = Employee::onlyTrashed()->with('branch')
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->orderBy('short_name');

        $type = $request->query('type');
        if (in_array($type, ['proper', 'improper'], true)) {
            $query->where('separation_type', $type);
        }

        return response()->json($query->get());
    }

    public function separate(Request $request, Employee $employee) {
        $this->assertBranchAccess($request, $employee);

        $data = $request->validate([
            'separation_type' => ['required', Rule::in(['proper', 'improper'])],
            'reason' => ['required', 'string'],
            'resignation_date' => ['nullable', 'date'],
        ]);

        if (empty(trim($data['reason']))) {
            return response()->json([
                'message' => 'A reason is required to remove an employee.',
                'errors' => ['reason' => ['A reason is required to remove an employee.']],
            ], 422);
        }

        return DB::transaction(function () use ($request, $employee, $data) {
            $employee->fill([
                'separation_type' => $data['separation_type'],
                'separation_reason' => trim($data['reason']),
                'resignation_date' => $data['resignation_date'] ?? now()->toDateString(),
            ]);
            $employee->save();

            AuditLog::create([
                'type' => 'employee',
                'employee_id' => $employee->id,
                'performed_by' => $request->user()->id,
                'action' => 'separated',
                'detail' => sprintf('Separated %s (%s) as %s resignation',
                    $employee->full_name, $employee->employee_code, $data['separation_type']),
                'reason' => trim($data['reason']),
            ]);

            $employee->delete();

            return response()->json(['message' => 'Employee separated.']);
        });
    }

    public function restore(Request $request, int $id) {
        $employee = Employee::onlyTrashed()->findOrFail($id);
        $this->assertBranchAccess($request, $employee);

        return DB::transaction(function () use ($request, $employee) {
            $employee->restore();
            $employee->fill([
                'separation_type' => null,
                'separation_reason' => null,
                'resignation_date' => null,
            ]);
            $employee->save();

            AuditLog::create([
                'type' => 'employee',
                'employee_id' => $employee->id,
                'performed_by' => $request->user()->id,
                'action' => 'restored',
                'detail' => "Restored employee {$employee->employee_code} ({$employee->full_name})",
            ]);

            return response()->json($employee->load('branch'));
        });
    }
}
