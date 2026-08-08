<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller {
    public function index(Request $request) {
        return response()->json(
            AuditLog::with(['employee', 'performedBy'])
                ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
                ->when($request->query('employee_id'), fn ($q, $id) => $q->where('employee_id', $id))
                ->latest()->get()
        );
    }
}
