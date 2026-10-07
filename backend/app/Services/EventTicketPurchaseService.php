<?php

namespace App\Services;

use App\Models\EventTicketOrder;
use App\Models\EventTicketType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventTicketPurchaseService
{
    /**
     * Create a temporary ticket order hold.
     */
    public function createHold(
        User $user,
        EventTicketType $ticketType,
        int $quantity
    ): EventTicketOrder {
        if ($quantity < 1) {
            throw ValidationException::withMessages([
                'quantity' => ['Quantity must be at least 1.'],
            ]);
        }

        return DB::transaction(function () use ($user, $ticketType, $quantity) {

            $ticketType = EventTicketType::query()
                ->with('event')
                ->whereKey($ticketType->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($ticketType->status !== 'active') {
                throw ValidationException::withMessages([
                    'ticket_type' => ['This ticket type is not available.'],
                ]);
            }

            if ($ticketType->event->status !== 'published') {
                throw ValidationException::withMessages([
                    'event' => ['This event is not available for ticket purchase.'],
                ]);
            }

            $now = now();

            if (
                $ticketType->sales_starts_at !== null &&
                $now->lt($ticketType->sales_starts_at)
            ) {
                throw ValidationException::withMessages([
                    'ticket_type' => ['Ticket sales have not started yet.'],
                ]);
            }

            if (
                $ticketType->sales_ends_at !== null &&
                $now->gt($ticketType->sales_ends_at)
            ) {
                throw ValidationException::withMessages([
                    'ticket_type' => ['Ticket sales have ended.'],
                ]);
            }

            $availableQuota =
                $ticketType->quota
                - $ticketType->sold
                - $ticketType->reserved;

            if ($quantity > $availableQuota) {
                throw ValidationException::withMessages([
                    'quantity' => [
                        "Only {$availableQuota} ticket(s) are available.",
                    ],
                ]);
            }

            $unitPrice = (float) $ticketType->price;
            $totalAmount = $unitPrice * $quantity;

            $order = EventTicketOrder::create([
                'order_code' => $this->generateOrderCode(),
                'user_id' => $user->id,
                'event_id' => $ticketType->event_id,
                'event_ticket_type_id' => $ticketType->id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_amount' => $totalAmount,
                'status' => 'held',
                'hold_expires_at' => now()->addMinutes((int) \App\Models\PlatformSetting::get('booking_hold_duration_minutes', 15)),
            ]);

            $ticketType->increment('reserved', $quantity);

            return $order->fresh([
                'user',
                'event',
                'ticketType',
            ]);
        });
    }

    /**
     * Confirm a held ticket order.
     */
    public function confirmOrder(
        EventTicketOrder $order
    ): EventTicketOrder {
        return DB::transaction(function () use ($order) {

            $order = EventTicketOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status !== 'held') {
                throw ValidationException::withMessages([
                    'order' => [
                        'Only held ticket orders can be confirmed.',
                    ],
                ]);
            }

            if (
                $order->hold_expires_at !== null &&
                now()->greaterThan($order->hold_expires_at)
            ) {
                throw ValidationException::withMessages([
                    'order' => [
                        'This ticket order has expired.',
                    ],
                ]);
            }

            $ticketType = EventTicketType::query()
                ->whereKey($order->event_ticket_type_id)
                ->lockForUpdate()
                ->firstOrFail();

            $ticketType->decrement(
                'reserved',
                $order->quantity
            );

            $ticketType->increment(
                'sold',
                $order->quantity
            );

            $order->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
                'hold_expires_at' => null,
            ]);

            return $order->fresh([
                'user',
                'event',
                'ticketType',
            ]);
        });
    }

    /**
     * Cancel a held ticket order manually.
     */
    public function cancelOrder(
        EventTicketOrder $order
    ): EventTicketOrder {
        return DB::transaction(function () use ($order) {

            $order = EventTicketOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status !== 'held') {
                throw ValidationException::withMessages([
                    'order' => [
                        'Only held ticket orders can be cancelled.',
                    ],
                ]);
            }

            $ticketType = EventTicketType::query()
                ->whereKey($order->event_ticket_type_id)
                ->lockForUpdate()
                ->firstOrFail();

            $ticketType->decrement(
                'reserved',
                $order->quantity
            );

            $order->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'hold_expires_at' => null,
            ]);

            return $order->fresh([
                'user',
                'event',
                'ticketType',
            ]);
        });
    }

    /**
     * Expire all ticket orders whose hold has expired.
     */
    public function expireHolds(): int
    {
        $expiredOrderIds = EventTicketOrder::query()
            ->where('status', 'held')
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<=', now())
            ->pluck('id');

        $expiredCount = 0;

        foreach ($expiredOrderIds as $orderId) {

            $expired = DB::transaction(function () use ($orderId) {

                $order = EventTicketOrder::query()
                    ->whereKey($orderId)
                    ->lockForUpdate()
                    ->first();

                if (!$order) {
                    return false;
                }

                /*
                 * Another process may have already confirmed
                 * or cancelled this order.
                 */
                if ($order->status !== 'held') {
                    return false;
                }

                if (
                    $order->hold_expires_at === null ||
                    now()->lt($order->hold_expires_at)
                ) {
                    return false;
                }

                $ticketType = EventTicketType::query()
                    ->whereKey($order->event_ticket_type_id)
                    ->lockForUpdate()
                    ->first();

                if (!$ticketType) {
                    return false;
                }

                $ticketType->decrement(
                    'reserved',
                    $order->quantity
                );

                $order->update([
                    'status' => 'expired',
                    'hold_expires_at' => null,
                ]);

                return true;
            });

            if ($expired) {
                $expiredCount++;
            }
        }

        return $expiredCount;
    }

    /**
     * Generate unique order code.
     */
    private function generateOrderCode(): string
    {
        do {
            $code = 'VYB-ORD-' . strtoupper(
                bin2hex(random_bytes(4))
            );
        } while (
            EventTicketOrder::query()
                ->where('order_code', $code)
                ->exists()
        );

        return $code;
    }
}