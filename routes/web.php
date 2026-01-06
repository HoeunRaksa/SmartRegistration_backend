<?php
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PaymentController;
use App\Events\PaymentStatusUpdated;
Route::get('/test-session', function () {
    session(['test_key' => 'Hello World']);
    $sessionValue = session('test_key');
    try {
        DB::connection()->getPdo();
        $dbStatus = 'Database connection OK';
    } catch (\Exception $e) {
        $dbStatus = 'Database connection failed: ' . $e->getMessage();
    }

    return response()->json([
        'session_value' => $sessionValue,
        'db_status' => $dbStatus,
    ]);
});




