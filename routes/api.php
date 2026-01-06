<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\Api\DropdownController;
Route::get('/departments', [DropdownController::class, 'departments']);
Route::get('/departments/{department_id}/majors', [DropdownController::class, 'majors']);
Route::prefix('payment')->group(function () {
    Route::post('/generate-qr', [PaymentController::class, 'generateQr']);
    Route::get('/check-status/{tranId}', [PaymentController::class, 'checkPaymentStatus']);
    Route::post('/callback', [PaymentController::class, 'paymentCallback']);  
});
Route::prefix('register')->group(function () {
    Route::post('/save', [RegistrationController::class, 'store']);  
});
