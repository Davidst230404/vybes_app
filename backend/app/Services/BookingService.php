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
            $resource = Resource::query()
                ->whereKey($resource->id)
                ->lockForUpdate()
                ->firstOrFail();

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
             * Get the price from the resource.
             */
            $unitPrice = $resource->base_price;

            /*
             * Calculate booking price.
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