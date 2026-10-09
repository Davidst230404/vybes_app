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
use App\Http\Controllers\Api\Admin\ApprovalController;
use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\BookingController as AdminBookingController;
use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\PlatformSettingController;
use App\Http\Controllers\Api\Admin\RefundController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Admin\VenueController as AdminVenueController;
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

/*
|--------------------------------------------------------------------------
| Admin CMS Routes
|--------------------------------------------------------------------------
|
| Rute administratif untuk CMS platform VYBES.
| Dilindungi oleh auth:sanctum dan middleware admin (memeriksa role admin / permission admin.manage).
|
*/

Route::prefix('admin')
    ->middleware([
        'auth:sanctum',
        'admin',
        'throttle:api-user',
    ])
    ->group(function () {
        // Dashboard Metrics
        Route::get('/dashboard', [DashboardController::class, 'index']);

        // Users Management
        Route::get('/users', [UserController::class, 'index']);
        Route::get('/users/{user}', [UserController::class, 'show'])->whereNumber('user');
        Route::patch('/users/{user}', [UserController::class, 'update'])->whereNumber('user');
        Route::post('/users/{user}/suspend', [UserController::class, 'suspend'])->whereNumber('user');
        Route::post('/users/{user}/reactivate', [UserController::class, 'reactivate'])->whereNumber('user');

        // Approvals (Merchants & Organizers)
        Route::get('/approvals', [ApprovalController::class, 'index']);
        Route::post('/approvals/merchants/{merchant}', [ApprovalController::class, 'updateMerchantStatus'])->whereNumber('merchant');
        Route::post('/approvals/organizers/{organizer}', [ApprovalController::class, 'updateOrganizerStatus'])->whereNumber('organizer');

        // Categories Management
        Route::get('/categories', [AdminCategoryController::class, 'index']);
        Route::post('/categories', [AdminCategoryController::class, 'store']);
        Route::get('/categories/{category}', [AdminCategoryController::class, 'show'])->whereNumber('category');
        Route::put('/categories/{category}', [AdminCategoryController::class, 'update'])->whereNumber('category');
        Route::delete('/categories/{category}', [AdminCategoryController::class, 'destroy'])->whereNumber('category');

        // Global Bookings Supervision
        Route::get('/bookings', [AdminBookingController::class, 'index']);
        Route::get('/bookings/{booking}', [AdminBookingController::class, 'show'])->whereNumber('booking');

        // Refunds Management
        Route::get('/refunds', [RefundController::class, 'index']);
        Route::get('/refunds/{refund}', [RefundController::class, 'show'])->whereNumber('refund');
        Route::post('/refunds', [RefundController::class, 'store']);

        // Global Venues Supervision
        Route::get('/venues', [AdminVenueController::class, 'index']);
        Route::get('/venues/{venue}', [AdminVenueController::class, 'show'])->whereNumber('venue');

        // Platform Settings (PRD BR-003, Section 5)
        Route::get('/settings', [PlatformSettingController::class, 'index']);
        Route::patch('/settings', [PlatformSettingController::class, 'update']);

        // Audit Logs (PRD Section 6, 15.3, 15.4)
        Route::get('/audit', [AuditLogController::class, 'index']);
    });