<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\UserSettingsController;

use App\Http\Controllers\PaymentController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\MajorController;
use App\Http\Controllers\MajorSubjectController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\RegistrationReportController;

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
| AUTH (PUBLIC)
|--------------------------------------------------------------------------
*/

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register/save', [RegistrationController::class, 'store']); // student self-register
Route::post('/registrations/{id}/pay-later', [RegistrationController::class, 'payLater']);

/*
|--------------------------------------------------------------------------
| PUBLIC READ DATA (NO LOGIN REQUIRED)
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
| PAYMENT (PUBLIC)
|--------------------------------------------------------------------------
*/
Route::prefix('payment')->group(function () {
    Route::post('/generate-qr', [PaymentController::class, 'generateQr']);
    Route::get('/check-status/{tranId}', [PaymentController::class, 'checkPaymentStatus']);
    Route::post('/callback', [PaymentController::class, 'paymentCallback']); // ABA webhook
});

/*
|--------------------------------------------------------------------------
| AUTHENTICATED USER ROUTES (ANY LOGGED-IN USER)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum'])->group(function () {

    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);

    // User Profile & Settings
    Route::get('/user/profile', [UserSettingsController::class, 'profile']);
    Route::put('/user/update-name', [UserSettingsController::class, 'updateName']);
    Route::put('/user/update-email', [UserSettingsController::class, 'updateEmail']);
    Route::put('/user/change-password', [UserSettingsController::class, 'changePassword']);
    Route::post('/user/upload-profile-picture', [UserSettingsController::class, 'uploadProfilePicture']);
    Route::delete('/user/delete-profile-picture', [UserSettingsController::class, 'deleteProfilePicture']);
    Route::post('/user/delete-account', [UserSettingsController::class, 'deleteAccount']);

    // Student can view own record only (optional: keep if your frontend needs it)
    Route::get('/students/{id}', [StudentController::class, 'show']);
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
| STAFF + ADMIN ROUTES (FULL MANAGEMENT)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:staff,admin'])->group(function () {

    // Registrations
    Route::get('/registers', [RegistrationController::class, 'index']);
    Route::get('/registers/{id}', [RegistrationController::class, 'show']);
    Route::put('/registers/{id}', [RegistrationController::class, 'update']);
    Route::delete('/registers/{id}', [RegistrationController::class, 'destroy']);
     Route::post('/admin/registrations/{id}/mark-paid', [RegistrationController::class, 'markPaidCash']);

    // Reports (filters => POST is better)
    Route::prefix('reports')->group(function () {
        Route::match(['GET', 'POST'], '/registrations', [RegistrationReportController::class, 'generate']);
        Route::match(['GET', 'POST'], '/registrations/pdf', [RegistrationReportController::class, 'exportPdf']);
        Route::get('/registrations/summary', [RegistrationReportController::class, 'summary']);
    });


    // Students (Admin/Staff can CRUD)
    Route::get('/students', [StudentController::class, 'index']);
    Route::post('/students', [StudentController::class, 'store']);
    Route::put('/students/{id}', [StudentController::class, 'update']);
    Route::patch('/students/{id}', [StudentController::class, 'update']);
    Route::delete('/students/{id}', [StudentController::class, 'destroy']);
    Route::post('/students/{id}/reset-password', [StudentController::class, 'resetPassword']);

    // Departments (Write)
    Route::post('/departments', [DepartmentController::class, 'store']);
    Route::put('/departments/{department}', [DepartmentController::class, 'update']);
    Route::patch('/departments/{department}', [DepartmentController::class, 'update']);
    Route::delete('/departments/{department}', [DepartmentController::class, 'destroy']);

    // Extra department endpoints
    Route::get('departments/faculties', [DepartmentController::class, 'getFaculties']);
    Route::get('departments/statistics', [DepartmentController::class, 'getStatistics']);

    // Majors (Write)
    Route::post('/majors', [MajorController::class, 'store']);
    Route::put('/majors/{major}', [MajorController::class, 'update']);
    Route::patch('/majors/{major}', [MajorController::class, 'update']);
    Route::delete('/majors/{major}', [MajorController::class, 'destroy']);

    // Subjects (Write)
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
Route::get('/test-registrations', function () {
    $data = [
        'total_registrations' => \App\Models\Registration::count(),
        'total_students' => \App\Models\Student::count(),
        'sample_registrations' => \App\Models\Registration::with(['department', 'major', 'student'])
            ->limit(3)
            ->get(),
    ];

    return response()->json([
        'success' => true,
        'message' => 'Test endpoint',
        'data' => $data
    ]);
});
