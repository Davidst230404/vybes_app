<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Resource;
use App\Services\BookingService;
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
}