<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;

class AuditLogController extends Controller {
    public function index() {
        return response()->json(
            AuditLog::with(['employee', 'performedBy'])->latest()->get()
        );
    }
}
