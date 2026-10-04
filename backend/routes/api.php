<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CheckInController;
use App\Http\Controllers\Api\EventTicketOrderController;
use App\Http\Controllers\Api\OrganizerEventController;
use App\Http\Controllers\Api\OrganizerParticipantController;
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

    /*
    |--------------------------------------------------------------------------
    | Public Authentication
    |--------------------------------------------------------------------------
    */

    // Register
    Route::post(
        '/register',
        [AuthController::class, 'register']
    );

    // Login
    Route::post(
        '/login',
        [AuthController::class, 'login']
    );


    /*
    |--------------------------------------------------------------------------
    | Authenticated User
    |--------------------------------------------------------------------------
    */

    Route::middleware('auth:sanctum')->group(function () {

        // Get authenticated user
        Route::get(
            '/me',
            [AuthController::class, 'me']
        );

        // Logout
        Route::post(
            '/logout',
            [AuthController::class, 'logout']
        );
    });
});


/*
|--------------------------------------------------------------------------
| Availability
|--------------------------------------------------------------------------
|
| Public endpoint untuk mengecek availability resource.
|
*/

Route::get(
    '/resources/{resource}/availability',
    [AvailabilityController::class, 'check']
);


/*
|--------------------------------------------------------------------------
| Regular Booking
|--------------------------------------------------------------------------
|
| Booking untuk venue/resource biasa:
| - Create booking hold
| - List booking user
| - Booking detail
| - Digital ticket
| - Payment session
| - Check-in
|
*/

Route::middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Booking
    |--------------------------------------------------------------------------
    */

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


    /*
    |--------------------------------------------------------------------------
    | Regular Booking Check-in
    |--------------------------------------------------------------------------
    */

    // Check-in regular booking ticket
    Route::post(
        '/check-in',
        [CheckInController::class, 'checkIn']
    );
});


/*
|--------------------------------------------------------------------------
| Event Ticket Orders
|--------------------------------------------------------------------------
|
| Customer membeli tiket event.
|
*/

Route::middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Event Ticket Order
    |--------------------------------------------------------------------------
    */

    // Create temporary ticket order / hold
    Route::post(
        '/events/{event}/ticket-orders',
        [EventTicketOrderController::class, 'store']
    );

    // Get ticket order detail
    Route::get(
        '/event-ticket-orders/{order}',
        [EventTicketOrderController::class, 'show']
    );

    // Cancel ticket order
    Route::post(
        '/event-ticket-orders/{order}/cancel',
        [EventTicketOrderController::class, 'cancel']
    );

    // Create payment session for event ticket
    Route::post(
        '/event-ticket-orders/{order}/payment-session',
        [PaymentController::class, 'createEventTicketSession']
    );
});


/*
|--------------------------------------------------------------------------
| Organizer Events
|--------------------------------------------------------------------------
|
| Organizer:
| - List event
| - Create event
| - Detail event
| - Update event
|
*/

Route::middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Event Management
    |--------------------------------------------------------------------------
    */

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

    // Update event
    Route::put(
        '/organizer/events/{event}',
        [OrganizerEventController::class, 'update']
    );
});


/*
|--------------------------------------------------------------------------
| Organizer Ticket Types
|--------------------------------------------------------------------------
|
| Organizer dapat:
| - Melihat ticket type
| - Membuat ticket type
| - Update ticket type
| - Delete ticket type
|
*/

Route::middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Ticket Type Management
    |--------------------------------------------------------------------------
    */

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
| Organizer Participants
|--------------------------------------------------------------------------
|
| Organizer dapat:
| - Melihat semua participant
| - Melihat detail participant
| - Check-in participant
|
*/

Route::middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Participant Management
    |--------------------------------------------------------------------------
    */

    // Get participants for organizer event
    Route::get(
        '/organizer/events/{event}/participants',
        [OrganizerParticipantController::class, 'index']
    );

    // Get participant detail
    Route::get(
        '/organizer/events/{event}/participants/{ticket}',
        [OrganizerParticipantController::class, 'show']
    );

    // Check-in participant
    Route::post(
        '/organizer/events/{event}/participants/{ticket}/check-in',
        [OrganizerParticipantController::class, 'checkIn']
    );
});


/*
|--------------------------------------------------------------------------
| Xendit Webhook
|--------------------------------------------------------------------------
|
| Endpoint ini TIDAK menggunakan auth:sanctum karena
| request berasal dari Xendit.
|
*/

Route::post(
    '/webhooks/xendit',
    [XenditWebhookController::class, 'handle']
);