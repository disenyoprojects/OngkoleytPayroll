<?php

use App\Http\Controllers\Admin\AttendanceAdminController;
use App\Http\Controllers\Admin\AttendanceDashboardController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\ClockController;
use App\Http\Controllers\Admin\EmployeeAttendanceController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\PayrollController;
use App\Http\Controllers\Admin\PayslipController;
use App\Http\Controllers\Admin\PayrollAdjustmentController;
use App\Http\Controllers\Admin\PayrollExportController;
use App\Http\Controllers\Admin\PayrollPdfController;
use App\Http\Controllers\Admin\PayrollSettingController;
use App\Http\Controllers\Admin\ThirteenthMonthController;
use App\Http\Controllers\Admin\ThirteenthMonthPayslipController;
use App\Http\Controllers\Auth\AdminSessionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/admin/login', [AdminSessionController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/admin/logout', [AdminSessionController::class, 'logout']);
    Route::get('/admin/me', [AdminSessionController::class, 'me']);
    Route::patch('/admin/attendance/{record}/adjust', [AttendanceAdminController::class, 'adjust']);
    Route::post('/admin/attendance/{record}/approve', [AttendanceAdminController::class, 'approve']);
    Route::get('/admin/attendance/today', [AttendanceDashboardController::class, 'today']);
    Route::get('/admin/clock/staff', [ClockController::class, 'staff']);
    Route::get('/admin/clock/status', [ClockController::class, 'status']);
    Route::post('/admin/clock/in', [ClockController::class, 'clockIn']);
    Route::post('/admin/clock/out', [ClockController::class, 'clockOut']);
    Route::get('/admin/payroll/daily', [PayrollController::class, 'daily']);
    Route::get('/admin/payroll/weekly', [PayrollController::class, 'weekly']);
    Route::get('/admin/payroll/period', [PayrollController::class, 'period']);
    Route::get('/admin/payroll/period/pdf', [PayrollController::class, 'periodPdf']);
    Route::get('/admin/payroll/export', [PayrollExportController::class, 'export']);
    Route::get('/admin/payroll/pdf', [PayrollPdfController::class, 'export']);
    Route::get('/admin/thirteenth-month', [ThirteenthMonthController::class, 'index']);
    Route::post('/admin/thirteenth-month/compute-all', [ThirteenthMonthController::class, 'computeAll']);
    Route::post('/admin/thirteenth-month/{employee}/compute', [ThirteenthMonthController::class, 'compute']);
    Route::post('/admin/thirteenth-month/{employee}/recompute', [ThirteenthMonthController::class, 'recompute']);
    Route::post('/admin/thirteenth-month/{employee}/adjust', [ThirteenthMonthController::class, 'adjust']);
    Route::post('/admin/thirteenth-month/{employee}/lock', [ThirteenthMonthController::class, 'lock']);
    Route::post('/admin/thirteenth-month/{employee}/unlock', [ThirteenthMonthController::class, 'unlock']);
    Route::post('/admin/thirteenth-month/{employee}/release', [ThirteenthMonthController::class, 'release']);
    Route::get('/admin/thirteenth-month/{employee}/payslip', [ThirteenthMonthPayslipController::class, 'show']);
    Route::get('/admin/settings', [PayrollSettingController::class, 'show']);
    Route::put('/admin/settings', [PayrollSettingController::class, 'update']);
    Route::get('/admin/audit-log', [AuditLogController::class, 'index']);
    Route::get('/admin/employees', [EmployeeController::class, 'index']);
    Route::get('/admin/employees/separated', [EmployeeController::class, 'separated']);
    Route::get('/admin/employees/{employee}/attendance', [EmployeeAttendanceController::class, 'index'])->withTrashed();
    Route::get('/admin/employees/{employee}/payslip', [PayslipController::class, 'show'])->withTrashed();
    Route::get('/admin/employees/{employee}/payslip/pdf', [PayslipController::class, 'pdf'])->withTrashed();
    Route::get('/admin/employees/{employee}/adjustments', [PayrollAdjustmentController::class, 'index'])->withTrashed();
    Route::post('/admin/employees/{employee}/adjustments', [PayrollAdjustmentController::class, 'store'])->withTrashed();
    Route::delete('/admin/adjustments/{adjustment}', [PayrollAdjustmentController::class, 'destroy']);
    Route::get('/admin/branches', [EmployeeController::class, 'branches']);
    Route::post('/admin/employees', [EmployeeController::class, 'store']);
    Route::put('/admin/employees/{employee}', [EmployeeController::class, 'update']);
    Route::post('/admin/employees/{employee}/separate', [EmployeeController::class, 'separate']);
    Route::post('/admin/employees/{id}/restore', [EmployeeController::class, 'restore']);
});
