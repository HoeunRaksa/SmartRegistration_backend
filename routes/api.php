<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\UserSettingsController;

use App\Http\Controllers\PaymentController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\MajorController;
use App\Http\Controllers\Api\MajorSubjectController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\RegistrationReportController;
use App\Http\Controllers\Api\AdminClassSessionController;
use App\Http\Controllers\Api\StudentCalendarController;
use App\Http\Controllers\Api\StudentDashboardController;
use App\Http\Controllers\Api\StudentProfileController;
use App\Http\Controllers\Api\StudentCourseController;
use App\Http\Controllers\Api\StudentScheduleController;
use App\Http\Controllers\Api\StudentGradeController;
use App\Http\Controllers\Api\StudentAssignmentController;
use App\Http\Controllers\Api\StudentAttendanceController;
use App\Http\Controllers\Api\StudentMessageController;

use App\Http\Controllers\Api\AdminCourseController;
use App\Http\Controllers\Api\AdminEnrollmentController;
use App\Http\Controllers\Api\AdminGradeController;
use App\Http\Controllers\Api\AdminAssignmentController;
use App\Http\Controllers\Api\AdminAttendanceController;
use App\Http\Controllers\Api\AdminScheduleController;
use App\Http\Controllers\Api\ClassGroupController;
use App\Http\Controllers\Api\MajorCapacityController;
use App\Http\Controllers\Api\MajorQuotaController;
use App\Http\Controllers\Api\TeacherController;
use App\Http\Controllers\Api\BuildingController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\StudentClassGroupController;
use App\Http\Controllers\Api\AdminStudentSearchController;
use App\Http\Controllers\Api\AdminEnrollmentLookupController;
use App\Http\Controllers\Api\ChatController;
use Illuminate\Support\Facades\Broadcast;

Broadcast::routes(['middleware' => ['auth:sanctum']]);
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

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::post('/register/save', [RegistrationController::class, 'store']); // student self-register
Route::post('/registrations/{id}/pay-later', [RegistrationController::class, 'payLater']);
// routes/api.php
Route::get('/registrations/can-register', [RegistrationController::class, 'canRegister']);
Route::get('/majors/{id}/capacity', [MajorCapacityController::class, 'show']);
Route::get('/major-quotas', [MajorQuotaController::class, 'index']);
Route::post('/major-quotas', [MajorQuotaController::class, 'store']);
Route::put('/major-quotas/{id}', [MajorQuotaController::class, 'update']);
Route::delete('/major-quotas/{id}', [MajorQuotaController::class, 'destroy']);

Route::get('class-groups/{classGroupId}/students', [StudentClassGroupController::class, 'classStudents']);

Route::prefix('students')->group(function () {
    Route::get('{studentId}/class-group', [StudentClassGroupController::class, 'show']);
    Route::post('{studentId}/class-group/assign', [StudentClassGroupController::class, 'assignManual']);
    Route::post('{studentId}/class-group/auto', [StudentClassGroupController::class, 'assignAuto']);
});
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

    // Calendar protected
    Route::get('/calendar', [StudentCalendarController::class, 'index']);

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

    // Student can view own record only (optional)
    Route::get('/students/{id}', [StudentController::class, 'show']);
    Route::get('/teachers', [TeacherController::class, 'index']);
    Route::get('/teachers/{id}', [TeacherController::class, 'show']);
    Route::post('/teachers', [TeacherController::class, 'store']);
    Route::post('/teachers/{id}', [TeacherController::class, 'update']); // FormData friendly
    Route::delete('/teachers/{id}', [TeacherController::class, 'destroy']);

    Route::get('/chat/{userId}', [ChatController::class, 'index']);
    Route::get('/conversations', [ChatController::class, 'conversations']);
    Route::post('/chat/{userId}', [ChatController::class, 'store']);
});

/*
|--------------------------------------------------------------------------
| TEACHER ROUTES
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:teacher'])->group(function () {
    // add teacher routes later
});

/*
|--------------------------------------------------------------------------
| COURSES + MAJOR SUBJECTS (teacher + staff + admin)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:teacher,staff,admin'])->group(function () {

    // Courses
    Route::apiResource('courses', CourseController::class);

    // ✅ Bulk create MajorSubjects (PLACE FIRST)
    Route::post('/major-subjects/bulk', [MajorSubjectController::class, 'storeBulk']);

    // MajorSubjects normal CRUD
    Route::apiResource('major-subjects', MajorSubjectController::class)
        ->only(['index', 'store', 'show', 'destroy']);

    Route::apiResource('class-groups', ClassGroupController::class);
});
/*
|--------------------------------------------------------------------------
| STAFF + ADMIN ROUTES (FULL MANAGEMENT)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:staff,admin'])->group(function () {

    Route::prefix('admin')->group(function () {

        // Enrollments
        Route::get('/enrollments', [AdminEnrollmentController::class, 'index']);
        Route::post('/enrollments', [AdminEnrollmentController::class, 'store']);
        Route::delete('/enrollments/{id}', [AdminEnrollmentController::class, 'destroy']);
        Route::put('/enrollments/{id}/status', [AdminEnrollmentController::class, 'updateStatus']);

        // Grades
        Route::get('/grades', [AdminGradeController::class, 'index']);
        Route::post('/grades', [AdminGradeController::class, 'store']);
        Route::put('/grades/{id}', [AdminGradeController::class, 'update']);
        Route::delete('/grades/{id}', [AdminGradeController::class, 'destroy']);

        // Assignments
        Route::get('/assignments', [AdminAssignmentController::class, 'index']);
        Route::post('/assignments', [AdminAssignmentController::class, 'store']);
        Route::put('/assignments/{id}', [AdminAssignmentController::class, 'update']);
        Route::delete('/assignments/{id}', [AdminAssignmentController::class, 'destroy']);
        Route::get('/assignments/{id}/submissions', [AdminAssignmentController::class, 'submissions']);
        Route::put('/submissions/{id}/grade', [AdminAssignmentController::class, 'gradeSubmission']);

        // Attendance
        Route::get('/attendance', [AdminAttendanceController::class, 'index']);
        Route::post('/attendance', [AdminAttendanceController::class, 'store']);
        Route::put('/attendance/{id}', [AdminAttendanceController::class, 'updateStatus']);
        Route::post('/class-sessions', [AdminAttendanceController::class, 'createSession']);

         // Class Schedules (your existing routes)
        Route::get('/schedules', [AdminScheduleController::class, 'index']);
        Route::post('/schedules', [AdminScheduleController::class, 'store']);
        Route::put('/schedules/{id}', [AdminScheduleController::class, 'update']);
        Route::delete('/schedules/{id}', [AdminScheduleController::class, 'destroy']);
        

        Route::get('/class-sessions', [AdminClassSessionController::class, 'index']);
        Route::get('/class-sessions/upcoming', [AdminClassSessionController::class, 'upcoming']);
        Route::get('/class-sessions/by-date/{date}', [AdminClassSessionController::class, 'byDate']);
        Route::get('/class-sessions/by-course/{courseId}', [AdminClassSessionController::class, 'byCourse']);
        Route::get('/class-sessions/{id}', [AdminClassSessionController::class, 'show']);
        Route::post('/class-sessions', [AdminClassSessionController::class, 'store']);
        Route::put('/class-sessions/{id}', [AdminClassSessionController::class, 'update']);
        Route::delete('/class-sessions/{id}', [AdminClassSessionController::class, 'destroy']);


        Route::get('/buildings', [BuildingController::class, 'index']);
        Route::get('/buildings/options', [BuildingController::class, 'options']);
        Route::get('/buildings/{id}', [BuildingController::class, 'show']);
        Route::post('/buildings', [BuildingController::class, 'store']);
        Route::put('/buildings/{id}', [BuildingController::class, 'update']);
        Route::delete('/buildings/{id}', [BuildingController::class, 'destroy']);
        
        // Rooms
        Route::get('/rooms', [RoomController::class, 'index']);
        Route::get('/rooms/options', [RoomController::class, 'options']);
        Route::get('/rooms/by-building/{buildingId}', [RoomController::class, 'byBuilding']);
        Route::post('/rooms/check-availability', [RoomController::class, 'checkAvailability']);
        Route::get('/rooms/{id}', [RoomController::class, 'show']);
        Route::post('/rooms', [RoomController::class, 'store']);
        Route::put('/rooms/{id}', [RoomController::class, 'update']);
        Route::delete('/rooms/{id}', [RoomController::class, 'destroy']);


      // Bulk operations
        Route::post('/class-sessions/generate', [AdminClassSessionController::class, 'generate']);
        Route::post('/class-sessions/bulk-delete', [AdminClassSessionController::class, 'bulkDelete']);
        Route::get('/courses/options', [AdminCourseController::class, 'options']);
        
        Route::get('/enrollment-lookup/class-groups', [AdminEnrollmentLookupController::class, 'classGroups']);
        Route::get('/enrollment-lookup/courses', [AdminEnrollmentLookupController::class, 'courses']);
        Route::get('/enrollment-lookup/students', [AdminEnrollmentLookupController::class, 'students']);
        Route::get('/students/search', [AdminStudentSearchController::class, 'search']);
       
        Route::match(['post', 'put'], '/registrations/{id}/mark-paid-cash', [RegistrationController::class, 'markPaidCash']);
    });

    // Registrations
    Route::get('/registers', [RegistrationController::class, 'index']);
    Route::get('/registers/{id}', [RegistrationController::class, 'show']);
    Route::put('/registers/{id}', [RegistrationController::class, 'update']);
    Route::delete('/registers/{id}', [RegistrationController::class, 'destroy']);



    // Reports
    Route::prefix('reports')->group(function () {
        Route::match(['GET', 'POST'], '/registrations', [RegistrationReportController::class, 'generate']);
        Route::match(['GET', 'POST'], '/registrations/pdf', [RegistrationReportController::class, 'exportPdf']);
        Route::get('/registrations/summary', [RegistrationReportController::class, 'summary']);
    });

    // Students CRUD
    Route::get('/students', [StudentController::class, 'index']);
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

    Route::post('/teachers/{id}/reset-password', [TeacherController::class, 'resetPassword']);
});

/*
|--------------------------------------------------------------------------
| STUDENT ROUTES
|--------------------------------------------------------------------------
*/




Route::middleware(['auth:sanctum', 'role:student'])->prefix('student')->group(function () {
    
    // ==================== DASHBOARD ====================
    // Get all dashboard data in one request (most efficient)
    Route::get('/dashboard', [StudentDashboardController::class, 'getDashboard']);
    
    // ==================== PROFILE ====================
    Route::get('/profile', [StudentProfileController::class, 'getProfile']);
    Route::put('/profile', [StudentProfileController::class, 'updateProfile']);
    
    // ==================== COURSES ====================
    Route::prefix('courses')->group(function () {
        Route::get('/enrolled', [StudentCourseController::class, 'getEnrolledCourses']);
        Route::get('/available', [StudentCourseController::class, 'getAvailableCourses']);
        Route::post('/enroll', [StudentCourseController::class, 'enrollCourse']);
        Route::delete('/{courseId}/drop', [StudentCourseController::class, 'dropCourse']);
    });
    
    // ==================== SCHEDULE ====================
    Route::prefix('schedule')->group(function () {
        Route::get('/', [StudentScheduleController::class, 'getSchedule']);
        Route::get('/today', [StudentScheduleController::class, 'getTodaySchedule']);
        Route::get('/week', [StudentScheduleController::class, 'getWeekSchedule']);
        Route::get('/upcoming', [StudentScheduleController::class, 'getUpcoming']);
        Route::get('/download', [StudentScheduleController::class, 'downloadSchedule']);
    });
    
    // ==================== GRADES ====================
    Route::prefix('grades')->group(function () {
        Route::get('/', [StudentGradeController::class, 'getGrades']);
        Route::get('/gpa', [StudentGradeController::class, 'getGpa']);
        Route::get('/transcript', [StudentGradeController::class, 'getTranscript']); // Optional
    });
    
    // ==================== ASSIGNMENTS ====================
    Route::prefix('assignments')->group(function () {
        Route::get('/', [StudentAssignmentController::class, 'getAssignments']);
        Route::post('/submit', [StudentAssignmentController::class, 'submitAssignment']);
        Route::get('/{assignmentId}', [StudentAssignmentController::class, 'getAssignment']);
    });
    
    // ==================== ATTENDANCE ====================
    Route::prefix('attendance')->group(function () {
        Route::get('/', [StudentAttendanceController::class, 'getAttendance']);
        Route::get('/stats', [StudentAttendanceController::class, 'getStats']);
    });
    
    // ==================== MESSAGES ====================
    Route::prefix('messages')->group(function () {
        Route::get('/conversations', [StudentMessageController::class, 'getConversations']);
        Route::get('/{conversationId}', [StudentMessageController::class, 'getMessages']);
        Route::post('/send', [StudentMessageController::class, 'sendMessage']);
        Route::put('/{messageId}/read', [StudentMessageController::class, 'markAsRead']); // Optional
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