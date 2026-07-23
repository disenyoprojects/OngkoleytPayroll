<?php

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
});

Route::get('/kiosk/staff', [KioskAuthController::class, 'staff']);
Route::post('/kiosk/verify-pin', [KioskAuthController::class, 'verifyPin']);

Route::middleware('kiosk.token')->group(function () {
    Route::post('/kiosk/clock-in', [AttendanceClockController::class, 'clockIn']);
    Route::post('/kiosk/clock-out', [AttendanceClockController::class, 'clockOut']);
    Route::get('/kiosk/today', [AttendanceClockController::class, 'today']);
});
