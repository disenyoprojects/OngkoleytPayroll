<?php

use App\Http\Controllers\Admin\AttendanceAdminController;
use App\Http\Controllers\Admin\AttendanceDashboardController;
use App\Http\Controllers\Admin\PayrollController;
use App\Http\Controllers\Admin\PayrollExportController;
use App\Http\Controllers\Admin\PayrollPdfController;
use App\Http\Controllers\Admin\ThirteenthMonthController;
use App\Http\Controllers\Auth\AdminSessionController;
use App\Http\Controllers\Kiosk\AttendanceClockController;
use App\Http\Controllers\Kiosk\KioskAuthController;
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
    Route::get('/admin/payroll/daily', [PayrollController::class, 'daily']);
    Route::get('/admin/payroll/weekly', [PayrollController::class, 'weekly']);
    Route::get('/admin/payroll/export', [PayrollExportController::class, 'export']);
    Route::get('/admin/payroll/pdf', [PayrollPdfController::class, 'export']);
    Route::get('/admin/thirteenth-month', [ThirteenthMonthController::class, 'index']);
    Route::post('/admin/thirteenth-month/compute-all', [ThirteenthMonthController::class, 'computeAll']);
    Route::post('/admin/thirteenth-month/{employee}/compute', [ThirteenthMonthController::class, 'compute']);
    Route::post('/admin/thirteenth-month/{employee}/recompute', [ThirteenthMonthController::class, 'recompute']);
});

Route::get('/kiosk/staff', [KioskAuthController::class, 'staff']);
Route::post('/kiosk/verify-pin', [KioskAuthController::class, 'verifyPin']);

Route::middleware('kiosk.token')->group(function () {
    Route::post('/kiosk/clock-in', [AttendanceClockController::class, 'clockIn']);
    Route::post('/kiosk/clock-out', [AttendanceClockController::class, 'clockOut']);
    Route::get('/kiosk/today', [AttendanceClockController::class, 'today']);
});
