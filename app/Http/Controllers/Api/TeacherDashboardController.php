<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Teacher;
use App\Models\ClassSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TeacherDashboardController extends Controller
{
    /**
     * Get statistics for teacher dashboard
     * GET /api/teacher/dashboard/stats
     */
    public function getStats(Request $request)
    {
        try {
            $teacher = Teacher::where('user_id', $request->user()->id)->firstOrFail();
            $courseIds = Course::where('teacher_id', $teacher->id)->pluck('id');

            $totalStudents = CourseEnrollment::whereIn('course_id', $courseIds)
                ->where('status', 'enrolled')
                ->distinct('student_id')
                ->count();

            $totalCourses = $courseIds->count();

            $upcomingSessions = ClassSession::with(['course.majorSubject.subject'])
                ->whereIn('course_id', $courseIds)
                ->where('session_date', '>=', now()->toDateString())
                ->orderBy('session_date')
                ->orderBy('start_time')
                ->limit(5)
                ->get()
                ->map(function($s) {
                    return [
                        'id' => $s->id,
                        'course' => $s->course?->majorSubject?->subject?->subject_name,
                        'date' => $s->session_date,
                        'time' => $s->start_time . ' - ' . $s->end_time,
                    ];
                });

            return response()->json([
                'data' => [
                    'total_students' => $totalStudents,
                    'total_courses' => $totalCourses,
                    'upcoming_sessions' => $upcomingSessions,
                    'years_teaching' => 4, // Placeholder if not in DB
                ]
            ], 200);
        } catch (\Throwable $e) {
            Log::error('TeacherDashboardController@getStats error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load dashboard stats'], 500);
        }
    }

    /**
     * Get authenticated teacher's profile
     */
    public function getProfile(Request $request)
    {
        $teacher = Teacher::with('user', 'department')
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
            
        return response()->json([
            'data' => $teacher
        ]);
    }

    /**
     * Update authenticated teacher's profile
     */
    public function updateProfile(Request $request)
    {
        $teacher = Teacher::where('user_id', $request->user()->id)->firstOrFail();
        $user = $request->user();

        $validated = $request->validate([
            // Teacher fields
            'phone_number' => 'nullable|string|max:30',
            'office_location' => 'nullable|string|max:255',
            'specialization' => 'nullable|string|max:255',
            'education' => 'nullable|string|max:255',
            
            // User fields
            'email' => 'required|email|unique:users,email,' . $user->id,
            'image' => 'nullable|image|max:2048',
        ]);

        try {
            DB::beginTransaction();

            // Update Teacher info
            $teacher->update([
                'phone_number' => $validated['phone_number'] ?? $teacher->phone_number,
                'office_location' => $validated['office_location'] ?? $teacher->office_location,
                'specialization' => $validated['specialization'] ?? $teacher->specialization,
                'education' => $validated['education'] ?? $teacher->education,
            ]);

            // Update User info (Email & Image)
            if ($request->has('email') && $validated['email'] !== $user->email) {
                $user->email = $validated['email'];
            }

            if ($request->hasFile('image')) {
                // Delete old image if exists
                if ($user->profile_picture_path && \Illuminate\Support\Facades\File::exists(public_path($user->profile_picture_path))) {
                    \Illuminate\Support\Facades\File::delete(public_path($user->profile_picture_path));
                }
                
                // Upload new
                $file = $request->file('image');
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('uploads/teachers'), $filename);
                $user->profile_picture_path = 'uploads/teachers/' . $filename;
            }

            $user->save();
            DB::commit();

            // Refresh to return updated data
            $teacher->refresh();
            $teacher->load('user', 'department');
            
            // Generate full URL for profile picture
            if ($teacher->user->profile_picture_path) {
                $teacher->user->profile_picture_url = asset($teacher->user->profile_picture_path);
            }

            return response()->json([
                'message' => 'Profile updated successfully',
                'data' => $teacher
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('TeacherDashboardController@updateProfile error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to update profile', 'error' => $e->getMessage()], 500);
        }
    }
}
