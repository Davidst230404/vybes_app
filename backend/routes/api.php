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

    /*
     * Rate limited untuk mengurangi brute-force login.
     */
    Route::post(
        '/register',
        [AuthController::class, 'register']
    )->middleware('throttle:auth-register');

    Route::post(
        '/login',
        [AuthController::class, 'login']
    )->middleware('throttle:auth-login');


    /*
    |--------------------------------------------------------------------------
    | Authenticated User
    |--------------------------------------------------------------------------
    */

    Route::middleware([
        'auth:sanctum',
        'throttle:api-user',
    ])->group(function () {

        Route::get(
            '/me',
            [AuthController::class, 'me']
        );

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
| Rate limiting tetap dipasang karena endpoint ini dapat dipanggil
| tanpa autentikasi.
|
*/

Route::get(
    '/resources/{resource}/availability',
    [AvailabilityController::class, 'check']
)
    ->whereNumber('resource')
    ->middleware('throttle:api-user');


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

Route::middleware([
    'auth:sanctum',
    'throttle:api-user',
])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Booking
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/bookings',
        [BookingController::class, 'index']
    );

    Route::post(
        '/bookings',
        [BookingController::class, 'store']
    );

    Route::get(
        '/bookings/{booking}',
        [BookingController::class, 'show']
    )->whereNumber('booking');

    Route::get(
        '/bookings/{booking}/ticket',
        [BookingController::class, 'ticket']
    )->whereNumber('booking');

    Route::post(
        '/bookings/{booking}/payment-session',
        [PaymentController::class, 'createSession']
    )->whereNumber('booking');


    /*
    |--------------------------------------------------------------------------
    | Regular Booking Check-in
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/check-in',
        [CheckInController::class, 'checkIn']
    )->middleware('throttle:api-check-in');
});


/*
|--------------------------------------------------------------------------
| Event Ticket Orders
|--------------------------------------------------------------------------
|
| Customer membeli tiket event.
|
*/

Route::middleware([
    'auth:sanctum',
    'throttle:api-user',
])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Event Ticket Order
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/events/{event}/ticket-orders',
        [EventTicketOrderController::class, 'store']
    )->whereNumber('event');

    Route::get(
        '/event-ticket-orders/{order}',
        [EventTicketOrderController::class, 'show']
    )->whereNumber('order');

    Route::post(
        '/event-ticket-orders/{order}/cancel',
        [EventTicketOrderController::class, 'cancel']
    )->whereNumber('order');

    Route::post(
        '/event-ticket-orders/{order}/payment-session',
        [PaymentController::class, 'createEventTicketSession']
    )->whereNumber('order');
});


/*
|--------------------------------------------------------------------------
| Organizer Events
|--------------------------------------------------------------------------
|
| Authorization detail dilakukan di controller:
| - role organizer
| - organizer profile
| - organizer approval
| - event ownership
|
*/

Route::middleware([
    'auth:sanctum',
    'throttle:api-user',
])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Event Management
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/organizer/events',
        [OrganizerEventController::class, 'index']
    );

    Route::post(
        '/organizer/events',
        [OrganizerEventController::class, 'store']
    );

    Route::get(
        '/organizer/events/{event}',
        [OrganizerEventController::class, 'show']
    )->whereNumber('event');

    Route::put(
        '/organizer/events/{event}',
        [OrganizerEventController::class, 'update']
    )->whereNumber('event');

    Route::post(
        '/organizer/events/{event}/cancel',
        [OrganizerEventController::class, 'cancel']
    )->whereNumber('event');

    Route::put(
        '/organizer/events/{event}/reschedule',
        [OrganizerEventController::class, 'reschedule']
    )->whereNumber('event');
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

Route::middleware([
    'auth:sanctum',
    'throttle:api-user',
])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Ticket Type Management
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/organizer/events/{event}/ticket-types',
        [OrganizerTicketTypeController::class, 'index']
    )->whereNumber('event');

    Route::post(
        '/organizer/events/{event}/ticket-types',
        [OrganizerTicketTypeController::class, 'store']
    )->whereNumber('event');

    Route::put(
        '/organizer/events/{event}/ticket-types/{ticketType}',
        [OrganizerTicketTypeController::class, 'update']
    )
        ->whereNumber('event')
        ->whereNumber('ticketType');

    Route::delete(
        '/organizer/events/{event}/ticket-types/{ticketType}',
        [OrganizerTicketTypeController::class, 'destroy']
    )
        ->whereNumber('event')
        ->whereNumber('ticketType');
});


/*
|--------------------------------------------------------------------------
| Organizer Participants
|--------------------------------------------------------------------------
|
| Organizer dapat:
| - Melihat semua participant
| - Export participant CSV
| - Melihat detail participant
| - Check-in participant
|
*/

Route::middleware([
    'auth:sanctum',
    'throttle:api-user',
])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Participant Management
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/organizer/events/{event}/participants',
        [OrganizerParticipantController::class, 'index']
    )->whereNumber('event');

    Route::get(
        '/organizer/events/{event}/participants/export',
        [OrganizerParticipantController::class, 'export']
    )->whereNumber('event');

    Route::get(
        '/organizer/events/{event}/participants/{ticket}',
        [OrganizerParticipantController::class, 'show']
    )
        ->whereNumber('event')
        ->whereNumber('ticket');

    /*
     * Check-in mempunyai limiter lebih ketat.
     *
     * Effective middleware:
     * - auth:sanctum
     * - throttle:api-user
     * - throttle:api-check-in
     */
    Route::post(
        '/organizer/events/{event}/participants/{ticket}/check-in',
        [OrganizerParticipantController::class, 'checkIn']
    )
        ->whereNumber('event')
        ->whereNumber('ticket')
        ->middleware('throttle:api-check-in');
});


/*
|--------------------------------------------------------------------------
| Xendit Webhook
|--------------------------------------------------------------------------
|
| Endpoint ini TIDAK menggunakan auth:sanctum karena request berasal
| dari Xendit.
|
| Authentication dilakukan oleh XenditWebhookController menggunakan
| x-callback-token.
|
| Jangan memasang throttle:api-user di sini karena webhook berasal
| dari provider dan tidak memiliki Sanctum user.
|
*/

Route::post(
    '/webhooks/xendit',
    [XenditWebhookController::class, 'handle']
);