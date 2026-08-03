<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\Request;

abstract class Controller
{
    /** Branch id the current user is limited to, or null for admins (all branches). */
    protected function branchFilter(Request $request): ?int {
        return $request->user()?->scopedBranchId();
    }

    /** 403 if a branch login tries to touch an employee outside its own branch. */
    protected function assertBranchAccess(Request $request, Employee $employee): void {
        $branchId = $this->branchFilter($request);
        if ($branchId !== null && (int) $employee->branch_id !== $branchId) {
            abort(403, 'This employee is not in your branch.');
        }
    }
}
