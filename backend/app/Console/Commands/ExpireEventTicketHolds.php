<?php

namespace App\Console\Commands;

use App\Services\EventTicketPurchaseService;
use Illuminate\Console\Command;

class ExpireEventTicketHolds extends Command
{
    protected $signature = 'event-ticket-orders:expire-holds';

    protected $description = 'Expire event ticket orders whose hold has expired';

    public function handle(
        EventTicketPurchaseService $purchaseService
    ): int {
        $expiredCount = $purchaseService->expireHolds();

        $this->info(
            "Expired {$expiredCount} event ticket order(s)."
        );

        return self::SUCCESS;
    }
}