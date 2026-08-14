<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Branch ids the current user is limited to, or null for admins (all
     * branches). A branch login may cover more than one site, so this is a
     * list. Scope with branchScoped() rather than a truthiness check —
     * an empty list means "no branches", not "every branch".
     */
    protected function branchFilter(Request $request): ?array {
        return $request->user()?->scopedBranchIds();
    }

    /**
     * The branch a write may actually land in. Admins get whatever was
     * submitted; a branch login keeps its choice when that branch is one it
     * covers, and is otherwise pinned to the first branch it does.
     */
    protected function confineBranch(Request $request, mixed $submitted): mixed {
        $branchIds = $this->branchFilter($request);

        if ($branchIds === null) {
            return $submitted;
        }

        return in_array((int) $submitted, $branchIds, true) ? (int) $submitted : ($branchIds[0] ?? null);
    }

    /** 403 if a branch login tries to touch an employee outside the branches it covers. */
    protected function assertBranchAccess(Request $request, Employee $employee): void {
        $branchIds = $this->branchFilter($request);
        if ($branchIds !== null && ! in_array((int) $employee->branch_id, $branchIds, true)) {
            abort(403, 'This employee is not in your branch.');
        }
    }
}
