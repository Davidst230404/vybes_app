<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Resource;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function createHold(
        int $userId,
        Resource $resource,
        Carbon $startsAt,
        Carbon $endsAt,
        int $quantity = 1
    ): Booking {
        return DB::transaction(function () use (
            $userId,
            $resource,
            $startsAt,
            $endsAt,
            $quantity
        ) {
            /*
             * Basic input validation.
             */
            if ($quantity < 1) {
                throw ValidationException::withMessages([
                    'quantity' => [
                        'The quantity must be at least 1.',
                    ],
                ]);
            }

            if ($startsAt >= $endsAt) {
                throw ValidationException::withMessages([
                    'ends_at' => [
                        'The booking end time must be after the start time.',
                    ],
                ]);
            }

            /*
             * Lock the resource row inside the same DB transaction
             * used for the availability check and booking creation.
             *
             * This is important for preventing concurrent requests
             * from both passing the same availability check.
             */
            $resource = Resource::query()
                ->with('venue.merchant')
                ->whereKey($resource->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Inactive resources must never be bookable.
             */
            if ($resource->status !== 'active') {
                throw ValidationException::withMessages([
                    'resource' => [
                        'The selected resource is not available.',
                    ],
                ]);
            }

            /*
             * Only published venues can receive bookings.
             */
            if (
                !$resource->venue ||
                $resource->venue->status !== 'published'
            ) {
                throw ValidationException::withMessages([
                    'venue' => [
                        'The selected venue is not available.',
                    ],
                ]);
            }

            /*
             * Only approved merchants can expose
             * their resources for booking.
             */
            if (
                !$resource->venue->merchant ||
                $resource->venue->merchant->status !== 'approved'
            ) {
                throw ValidationException::withMessages([
                    'merchant' => [
                        'The venue owner is not approved.',
                    ],
                ]);
            }

            /*
             * Re-check availability while the resource row is locked.
             *
             * AvailabilityService also validates:
             * - resource state
             * - venue publication
             * - merchant approval
             * - operating schedule
             * - conflicting held/confirmed bookings
             */
            $availabilityService = app(AvailabilityService::class);

            if (!$availabilityService->isAvailable(
                $resource,
                $startsAt,
                $endsAt
            )) {
                throw ValidationException::withMessages([
                    'resource' => [
                        'The selected resource is not available.',
                    ],
                ]);
            }

            /*
             * Get the current price from the resource.
             *
             * The client-provided amount is intentionally not trusted.
             */
            $unitPrice = $resource->base_price;

            /*
             * Calculate the amount on the server.
             */
            $subtotal = $unitPrice * $quantity;
            $totalAmount = $subtotal;

            /*
             * Create booking hold.
             */
            $booking = Booking::create([
                'booking_code' => $this->generateBookingCode(),
                'user_id' => $userId,
                'venue_id' => $resource->venue_id,
                'status' => 'held',
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'quantity' => $quantity,
                'subtotal' => $subtotal,
                'total_amount' => $totalAmount,
                'hold_expires_at' => now()->addMinutes(15),
            ]);

            /*
             * Create booking item.
             */
            BookingItem::create([
                'booking_id' => $booking->id,
                'resource_id' => $resource->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'subtotal' => $subtotal,
            ]);

            return $booking->load([
                'venue',
                'items.resource',
            ]);
        });
    }

    private function generateBookingCode(): string
    {
        do {
            $code = 'VYB-' . strtoupper(
                substr(bin2hex(random_bytes(4)), 0, 8)
            );
        } while (Booking::where('booking_code', $code)->exists());

        return $code;
    }
}