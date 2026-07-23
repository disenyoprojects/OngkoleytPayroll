<?php

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\KioskTokenService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class KioskAuthController extends Controller {
    public function __construct(private KioskTokenService $tokens) {}

    public function staff() {
        return response()->json(
            Employee::whereNull('resignation_date')
                ->orWhere('resignation_date', '>=', now())
                ->with('branch')
                ->orderBy('short_name')
                ->get(['id', 'short_name', 'full_name', 'role', 'branch_id'])
        );
    }

    public function verifyPin(Request $request) {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'pin' => ['required', 'string', 'size:4'],
        ]);

        $employee = Employee::findOrFail($data['employee_id']);

        if (! $employee->verifyPin($data['pin'])) {
            throw ValidationException::withMessages(['pin' => ['Incorrect PIN.']]);
        }

        return response()->json(['token' => $this->tokens->issue($employee)]);
    }
}
