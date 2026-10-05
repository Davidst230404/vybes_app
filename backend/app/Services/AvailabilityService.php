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
        /*
         * Invalid time range must never be considered available.
         */
        if ($startsAt >= $endsAt) {
            return false;
        }

        /*
         * Never expose or allocate availability for resources
         * that are not active.
         */
        if ($resource->status !== 'active') {
            return false;
        }

        /*
         * A resource is only bookable when its venue exists
         * and is currently published.
         */
        $venue = $resource->venue;

        if (!$venue || $venue->status !== 'published') {
            return false;
        }

        /*
         * A venue is only bookable when its merchant exists
         * and has been approved.
         */
        $merchant = $venue->merchant;

        if (!$merchant || $merchant->status !== 'approved') {
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