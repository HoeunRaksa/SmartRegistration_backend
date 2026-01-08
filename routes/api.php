<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\Api\DropdownController;
use App\Http\Controllers\MajorController;
use App\Http\Controllers\MajorSubjectController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\Api\AuthController;

// -------- Auth --------
Route::post('/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);

// -------- Dropdown APIs --------
Route::get('/departments', [DropdownController::class, 'departments']);
Route::get('/departments/{department_id}/majors', [DropdownController::class, 'majors']);

// -------- Payment APIs --------
Route::prefix('payment')->group(function () {
    Route::post('/generate-qr', [PaymentController::class, 'generateQr']);
    Route::get('/check-status/{tranId}', [PaymentController::class, 'checkPaymentStatus']);
    Route::post('/callback', [PaymentController::class, 'paymentCallback']);
});

// -------- Registration (student only) --------
Route::middleware(['auth:sanctum', 'role:student'])->group(function () {
    Route::post('/register/save', [RegistrationController::class, 'store']);
});

// -------- Academic Management (teacher/staff only) --------
Route::middleware(['auth:sanctum', 'role:teacher'])->group(function () {
    Route::apiResource('majors', MajorController::class);
    Route::apiResource('major-subjects', MajorSubjectController::class)
        ->only(['index', 'store', 'show', 'destroy']);
    Route::apiResource('courses', CourseController::class);
});
