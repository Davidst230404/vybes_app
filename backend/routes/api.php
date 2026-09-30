<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CheckInController;
use App\Http\Controllers\Api\EventTicketOrderController;
use App\Http\Controllers\Api\OrganizerEventController;
use App\Http\Controllers\Api\OrganizerTicketTypeController;
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

    // Create payment session for regular booking
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
| Event Ticket Orders
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    // Create temporary ticket order / hold
    Route::post(
        '/events/{event}/ticket-orders',
        [EventTicketOrderController::class, 'store']
    );

    // Get customer's ticket order
    Route::get(
        '/event-ticket-orders/{order}',
        [EventTicketOrderController::class, 'show']
    );

    // Cancel ticket order
    Route::post(
        '/event-ticket-orders/{order}/cancel',
        [EventTicketOrderController::class, 'cancel']
    );

    // Create payment session for event ticket order
    Route::post(
        '/event-ticket-orders/{order}/payment-session',
        [PaymentController::class, 'createEventTicketSession']
    );
});


/*
|--------------------------------------------------------------------------
| Organizer Events
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    // Get organizer events
    Route::get(
        '/organizer/events',
        [OrganizerEventController::class, 'index']
    );

    // Create event
    Route::post(
        '/organizer/events',
        [OrganizerEventController::class, 'store']
    );

    // Get event detail
    Route::get(
        '/organizer/events/{event}',
        [OrganizerEventController::class, 'show']
    );
});


/*
|--------------------------------------------------------------------------
| Organizer Ticket Types
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    // Get ticket types
    Route::get(
        '/organizer/events/{event}/ticket-types',
        [OrganizerTicketTypeController::class, 'index']
    );

    // Create ticket type
    Route::post(
        '/organizer/events/{event}/ticket-types',
        [OrganizerTicketTypeController::class, 'store']
    );

    // Update ticket type
    Route::put(
        '/organizer/events/{event}/ticket-types/{ticketType}',
        [OrganizerTicketTypeController::class, 'update']
    );

    // Delete ticket type
    Route::delete(
        '/organizer/events/{event}/ticket-types/{ticketType}',
        [OrganizerTicketTypeController::class, 'destroy']
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