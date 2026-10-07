<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\BookingResource;
use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BookingController extends Controller
{
    /**
     * Global read-only list of bookings with pagination and filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Booking::with([
            'user',
            'venue',
        ]);

        if ($request->filled('booking_code')) {
            $code = '%' . trim((string) $request->input('booking_code')) . '%';
            $query->where('booking_code', 'like', $code);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('venue_id')) {
            $query->where('venue_id', $request->input('venue_id'));
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $bookings = $query->latest('id')->paginate($perPage);

        return BookingResource::collection($bookings);
    }

    /**
     * Show booking detail with associated items, resources, and payment.
     */
    public function show(Booking $booking): JsonResponse
    {
        $booking->load([
            'user',
            'venue',
            'items.resource',
            'payment',
        ]);

        return response()->json([
            'data' => new BookingResource($booking),
        ]);
    }
}
