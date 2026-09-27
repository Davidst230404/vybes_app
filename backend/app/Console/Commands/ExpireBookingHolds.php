<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;

class ExpireBookingHolds extends Command
{
    protected $signature = 'bookings:expire-holds';

    protected $description = 'Expire booking holds that have passed their expiration time';

    public function handle(): int
    {
        $expiredCount = Booking::query()
            ->where('status', 'held')
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<=', now())
            ->update([
                'status' => 'expired',
            ]);

        $this->info(
            "Expired {$expiredCount} booking hold(s)."
        );

        return self::SUCCESS;
    }
}