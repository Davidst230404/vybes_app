<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Resource;
use App\Services\BookingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
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
}