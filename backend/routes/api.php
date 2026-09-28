<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CheckInController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\XenditWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {

    // Register
    Route::post('/register', [AuthController::class, 'register']);

    // Login
    Route::post('/login', [AuthController::class, 'login']);

    /*
    |--------------------------------------------------------------------------
    | Authenticated User
    |--------------------------------------------------------------------------
    */

    Route::middleware('auth:sanctum')->group(function () {

        // Get authenticated user
        Route::get('/me', [AuthController::class, 'me']);

        // Logout
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});


/*
|--------------------------------------------------------------------------
| Availability
|--------------------------------------------------------------------------
*/

// Check resource availability
Route::get(
    '/resources/{resource}/availability',
    [AvailabilityController::class, 'check']
);


/*
|--------------------------------------------------------------------------
| Booking & Check-in
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    // Get user's bookings
    Route::get(
        '/bookings',
        [BookingController::class, 'index']
    );

    // Create booking hold
    Route::post(
        '/bookings',
        [BookingController::class, 'store']
    );

    // Get booking detail
    Route::get(
        '/bookings/{booking}',
        [BookingController::class, 'show']
    );

    // Get digital ticket
    Route::get(
        '/bookings/{booking}/ticket',
        [BookingController::class, 'ticket']
    );

    // Create payment session
    Route::post(
        '/bookings/{booking}/payment-session',
        [PaymentController::class, 'createSession']
    );

    // Check-in ticket
    Route::post(
        '/check-in',
        [CheckInController::class, 'checkIn']
    );
});


/*
|--------------------------------------------------------------------------
| Xendit Webhook
|--------------------------------------------------------------------------
*/

// Receive payment notifications from Xendit
Route::post(
    '/webhooks/xendit',
    [XenditWebhookController::class, 'handle']
);