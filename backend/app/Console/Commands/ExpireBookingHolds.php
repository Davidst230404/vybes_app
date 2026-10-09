<?php

namespace App\Console\Commands;

use App\Services\BookingService;
use Illuminate\Console\Command;

class ExpireBookingHolds extends Command
{
    protected $signature = 'bookings:expire-holds';

    protected $description = 'Expire booking holds that have passed their expiration time';

    public function handle(BookingService $bookingService): int
    {
        $expiredCount = $bookingService->expireHolds();

        $this->info(
            "Expired {$expiredCount} booking hold(s)."
        );

        return self::SUCCESS;
    }
}