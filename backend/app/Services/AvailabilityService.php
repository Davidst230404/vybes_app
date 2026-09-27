<?php

namespace App\Services;

use App\Models\Resource;
use Carbon\Carbon;

class AvailabilityService
{
    /**
     * Check whether a resource is available
     * for the requested time range.
     */
    public function isAvailable(
        Resource $resource,
        Carbon $startsAt,
        Carbon $endsAt
    ): bool {
        if ($startsAt >= $endsAt) {
            return false;
        }

        /*
         * Check resource schedule.
         */
        $dayOfWeek = $startsAt->dayOfWeek;

        $schedule = $resource->schedules()
            ->where('day_of_week', $dayOfWeek)
            ->where('is_available', true)
            ->where('start_time', '<=', $startsAt->format('H:i:s'))
            ->where('end_time', '>=', $endsAt->format('H:i:s'))
            ->exists();

        if (!$schedule) {
            return false;
        }

        /*
         * Check existing bookings.
         *
         * A booking is considered conflicting when:
         *
         * existing_start < requested_end
         * AND
         * existing_end > requested_start
         */
        $hasConflict = $resource->bookingItems()
            ->whereHas('booking', function ($query) {
                $query->whereIn('status', [
                    'held',
                    'confirmed',
                ]);
            })
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();

        return !$hasConflict;
    }
}