<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DropdownController;
use App\Http\Controllers\Api\StaffController;

use App\Http\Controllers\PaymentController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\MajorController;
use App\Http\Controllers\MajorSubjectController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\api\DepartmentController;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| Prefix: /api
| Auth: Laravel Sanctum
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| AUTHENTICATION
|--------------------------------------------------------------------------
*/
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);

/*
|--------------------------------------------------------------------------
| PUBLIC / DROPDOWN DATA
|--------------------------------------------------------------------------
*/
Route::get('/departments', [DropdownController::class, 'departments']);
Route::get('/departments/{department_id}/majors', [DropdownController::class, 'majors']);

/*
|--------------------------------------------------------------------------
| PAYMENT (PUBLIC CALLBACK, SECURE GENERATION)
|--------------------------------------------------------------------------
*/
Route::prefix('payment')->group(function () {
    Route::post('/generate-qr', [PaymentController::class, 'generateQr']);
    Route::get('/check-status/{tranId}', [PaymentController::class, 'checkPaymentStatus']);

    // Payment gateway callback (must stay public)
    Route::post('/callback', [PaymentController::class, 'paymentCallback']);
});

/*
|--------------------------------------------------------------------------
| STUDENT ROUTES
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:student'])->group(function () {
    Route::post('/register/save', [RegistrationController::class, 'store']);
});

/*
|--------------------------------------------------------------------------
| TEACHER ROUTES
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:teacher'])->group(function () {
    Route::apiResource('majors', MajorController::class);
    Route::apiResource('major-subjects', MajorSubjectController::class)
        ->only(['index', 'store', 'show', 'destroy']);
    Route::apiResource('courses', CourseController::class);
});

/*
|--------------------------------------------------------------------------
| STAFF + ADMIN ROUTES
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:staff,admin'])->group(function () {

    Route::post('/staffs', [StaffController::class, 'store']);
     Route::post('/departments', [DepartmentController::class, 'store']);
    /*
    |--------------------------------------------------------------------------
    | DEBUG ROUTES (REMOVE IN PRODUCTION)
    |--------------------------------------------------------------------------
    */
    Route::post('/staffs-test', function (Request $request) {
        return response()->json([
            'method' => $request->method(),
            'content_type' => $request->header('Content-Type'),
            'all' => $request->all(),
            'files' => $request->allFiles(),
            'raw' => $request->getContent(),
        ]);
    });

});
