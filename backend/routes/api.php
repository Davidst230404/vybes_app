<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvailabilityController;
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