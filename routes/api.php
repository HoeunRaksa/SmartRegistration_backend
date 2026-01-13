<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\DepartmentController;

use App\Http\Controllers\PaymentController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\MajorController;
use App\Http\Controllers\MajorSubjectController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\StudentController;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
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
| AUTHENTICATION (PUBLIC)
|--------------------------------------------------------------------------
*/
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register/save', [RegistrationController::class, 'store']); // ✅ STUDENT SELF-REGISTER
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);

/*
|--------------------------------------------------------------------------
| PUBLIC DATA (NO LOGIN REQUIRED)
|--------------------------------------------------------------------------
*/
Route::get('/departments', [DepartmentController::class, 'index']);
Route::get('/departments/{department_id}/majors', [DepartmentController::class, 'majors']);

Route::get('/majors', [MajorController::class, 'index']);
Route::get('/majors/{major}', [MajorController::class, 'show']);

Route::get('/subjects', [SubjectController::class, 'index']);
Route::get('/subjects/{id}', [SubjectController::class, 'show']);

/*
|--------------------------------------------------------------------------
| PAYMENT (PUBLIC + AUTHENTICATED)
|--------------------------------------------------------------------------
*/
Route::prefix('payment')
    ->withoutMiddleware([
        EnsureFrontendRequestsAreStateful::class,
        ThrottleRequests::class,
    ])
    ->group(function () {
        Route::post('/generate-qr', [PaymentController::class, 'generateQr']);
        Route::get('/check-status/{tranId}', [PaymentController::class, 'checkPaymentStatus']);
        Route::post('/callback', [PaymentController::class, 'paymentCallback']);
        Route::get('/registration/{registrationId}', [PaymentController::class, 'getRegistrationPayment']);
    });



/*
|--------------------------------------------------------------------------
| STUDENT ROUTES (LOGIN REQUIRED)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:student'])->group(function () {
    // Student-only routes (profile, view own registration, etc.)
});

/*
|--------------------------------------------------------------------------
| TEACHER ROUTES
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:teacher'])->group(function () {
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
    // Registrations
    Route::get('/registers', [RegistrationController::class, 'index']); 
    Route::get('/registers/{id}', [RegistrationController::class, 'show']); 
    Route::put('/registers/{id}', [RegistrationController::class, 'update']);
    Route::delete('/registers/{id}', [RegistrationController::class, 'destroy']);
    
    // Students
    Route::get('/students', [StudentController::class, 'index']);
    Route::get('/students/{id}', [StudentController::class, 'show']);
    Route::post('/students', [StudentController::class, 'store']);
    Route::put('/students/{id}', [StudentController::class, 'update']);
    Route::patch('/students/{id}', [StudentController::class, 'update']);
    Route::delete('/students/{id}', [StudentController::class, 'destroy']);

    // Departments
    Route::post('/departments', [DepartmentController::class, 'store']);
    Route::put('/departments/{department}', [DepartmentController::class, 'update']);
    Route::patch('/departments/{department}', [DepartmentController::class, 'update']);
    Route::delete('/departments/{department}', [DepartmentController::class, 'destroy']);

    // Majors (WRITE ONLY)
    Route::post('/majors', [MajorController::class, 'store']);
    Route::put('/majors/{major}', [MajorController::class, 'update']);
    Route::patch('/majors/{major}', [MajorController::class, 'update']);
    Route::delete('/majors/{major}', [MajorController::class, 'destroy']);

    // Subjects (WRITE ONLY)
    Route::post('/subjects', [SubjectController::class, 'store']);
    Route::put('/subjects/{id}', [SubjectController::class, 'update']);
    Route::patch('/subjects/{id}', [SubjectController::class, 'update']);
    Route::delete('/subjects/{id}', [SubjectController::class, 'destroy']);

    // Staff management
    Route::prefix('staff')->group(function () {
        Route::get('/', [StaffController::class, 'index']);
        Route::post('/', [StaffController::class, 'store']);
        Route::get('/{id}', [StaffController::class, 'show']);
        Route::put('/{id}', [StaffController::class, 'update']);
        Route::patch('/{id}', [StaffController::class, 'update']);
        Route::delete('/{id}', [StaffController::class, 'destroy']);
    });
});

/*
|--------------------------------------------------------------------------
| DEBUG (REMOVE IN PRODUCTION)
|--------------------------------------------------------------------------
*/
