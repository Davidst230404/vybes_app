<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Resource;
use App\Services\BookingService;
use App\Services\QrCodeService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $bookings = $request->user()
            ->bookings()
            ->with([
                'venue',
                'items.resource',
                'paymentSession',
                'payment',
            ])
            ->latest()
            ->paginate(10);

        return response()->json([
            'data' => $bookings,
        ]);
    }

    public function store(
        Request $request,
        BookingService $bookingService
    ): JsonResponse {
        $validated = $request->validate([
            'resource_id' => ['required', 'integer', 'exists:resources,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'quantity' => ['nullable', 'integer', 'min:1'],
        ]);

        $resource = Resource::findOrFail(
            $validated['resource_id']
        );

        $booking = $bookingService->createHold(
            $request->user()->id,
            $resource,
            Carbon::parse($validated['starts_at']),
            Carbon::parse($validated['ends_at']),
            $validated['quantity'] ?? 1
        );

        return response()->json([
            'message' => 'Booking hold created successfully.',
            'data' => [
                'booking' => $booking,
            ],
        ], 201);
    }

    public function show(
        Request $request,
        Booking $booking
    ): JsonResponse {
        if ($booking->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'You are not allowed to access this booking.',
            ], 403);
        }

        $booking->load([
            'venue',
            'items.resource',
            'paymentSession',
            'payment',
        ]);

        return response()->json([
            'data' => [
                'booking' => $booking,
            ],
        ]);
    }

    public function ticket(
        Request $request,
        Booking $booking,
        QrCodeService $qrCodeService
    ): JsonResponse {
        /*
        |--------------------------------------------------------------------------
        | Authorization
        |--------------------------------------------------------------------------
        | Pastikan booking hanya bisa diakses oleh pemiliknya.
        */

        if ($booking->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'You are not allowed to access this booking.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Booking Status
        |--------------------------------------------------------------------------
        | Digital ticket hanya tersedia setelah booking confirmed.
        */

        if ($booking->status !== 'confirmed') {
            return response()->json([
                'message' => 'Ticket is only available for confirmed bookings.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Load Ticket
        |--------------------------------------------------------------------------
        */

        $booking->load([
            'venue',
            'items.resource',
            'ticket',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Ticket Availability
        |--------------------------------------------------------------------------
        */

        if (!$booking->ticket) {
            return response()->json([
                'message' => 'Digital ticket has not been issued yet.',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Ticket Status
        |--------------------------------------------------------------------------
        */

        if ($booking->ticket->status === 'cancelled') {
            return response()->json([
                'message' => 'This ticket has been cancelled.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Generate QR Code
        |--------------------------------------------------------------------------
        */

        $qrImage = $qrCodeService->generateDataUri(
            $booking->ticket->qr_payload
        );

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'data' => [
                'ticket' => [
                    'id' => $booking->ticket->id,
                    'booking_id' => $booking->ticket->booking_id,
                    'ticket_code' => $booking->ticket->ticket_code,
                    'status' => $booking->ticket->status,
                    'qr_payload' => $booking->ticket->qr_payload,
                    'qr_image' => $qrImage,
                    'issued_at' => $booking->ticket->issued_at,
                    'checked_in_at' => $booking->ticket->checked_in_at,
                ],
                'booking' => $booking,
            ],
        ]);
    }
}