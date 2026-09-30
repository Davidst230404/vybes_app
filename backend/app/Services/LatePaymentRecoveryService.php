<?php

namespace App\Services;

use App\Models\EventTicketOrder;
use App\Models\EventTicketType;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LatePaymentRecoveryService
{
    public function __construct(
        private PaymentRefundService $paymentRefundService
    ) {
    }

    /**
     * Recover an event ticket payment that was captured
     * after the original ticket order hold expired.
     *
     * Flow:
     *
     * 1. Lock payment and order.
     * 2. Handle idempotent states first.
     * 3. For expired orders, lock the ticket type.
     * 4. Reconcile the expired order's reservation state.
     * 5. Re-check inventory atomically.
     * 6. If inventory is available, confirm and generate tickets.
     * 7. If inventory is unavailable, commit DB state first.
     * 8. Request the refund after the DB transaction commits.
     */
    public function recoverEventTicketPayment(
        Payment $payment
    ): EventTicketOrder {
        $refundPayment = null;
        $refundAmount = null;

        $order = DB::transaction(function () use (
            $payment,
            &$refundPayment,
            &$refundAmount
        ) {
            /*
             * Lock the payment first so concurrent webhook deliveries
             * cannot process the same payment independently.
             */
            $payment = Payment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($payment->status !== 'paid') {
                throw new RuntimeException(
                    'Late payment recovery requires a paid payment.'
                );
            }

            if ($payment->event_ticket_order_id === null) {
                throw new RuntimeException(
                    'Payment is not associated with an event ticket order.'
                );
            }

            /*
             * Lock the order so payment.capture retries and expiration
             * cannot mutate the same order concurrently.
             */
            $order = EventTicketOrder::query()
                ->whereKey($payment->event_ticket_order_id)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                throw new RuntimeException(
                    'Event ticket order not found.'
                );
            }

            /*
             * ---------------------------------------------------------
             * IDEMPOTENT: ALREADY CONFIRMED
             * ---------------------------------------------------------
             *
             * A previous webhook may already have completed recovery.
             * Make sure tickets exist, but do not change inventory again.
             */
            if ($order->status === 'confirmed') {
                app(EventTicketService::class)
                    ->generateTickets($order);

                return $order->fresh([
                    'user',
                    'event',
                    'ticketType',
                    'tickets',
                ]);
            }

            /*
             * ---------------------------------------------------------
             * NORMAL PAYMENT PATH: STILL HELD
             * ---------------------------------------------------------
             *
             * Payment arrived before the hold expired.
             *
             * EventTicketPurchaseService is responsible for:
             *
             * reserved -= quantity
             * sold += quantity
             * held -> confirmed
             */
            if ($order->status === 'held') {
                $purchaseService = app(
                    EventTicketPurchaseService::class
                );

                $order = $purchaseService->confirmOrder($order);

                app(EventTicketService::class)
                    ->generateTickets($order);

                return $order->fresh([
                    'user',
                    'event',
                    'ticketType',
                    'tickets',
                ]);
            }

            /*
             * ---------------------------------------------------------
             * OTHER TERMINAL STATES
             * ---------------------------------------------------------
             *
             * There is nothing for late-payment recovery to do.
             */
            if ($order->status !== 'expired') {
                return $order->fresh([
                    'user',
                    'event',
                    'ticketType',
                    'tickets',
                ]);
            }

            /*
             * ---------------------------------------------------------
             * EXPIRED ORDER
             * ---------------------------------------------------------
             *
             * The order has already lost its normal reservation.
             *
             * We now lock the ticket type and make PostgreSQL the
             * source of truth for the current inventory.
             */
            $ticketType = EventTicketType::query()
                ->whereKey($order->event_ticket_type_id)
                ->lockForUpdate()
                ->first();

            if (!$ticketType) {
                throw new RuntimeException(
                    'Event ticket type not found.'
                );
            }

            /*
             * ---------------------------------------------------------
             * INVENTORY RECONCILIATION
             * ---------------------------------------------------------
             *
             * An expired order must not remain represented as an
             * active reservation.
             *
             * However, we must NEVER blindly decrement reserved here.
             *
             * The current order is already expired and therefore has
             * no active hold.
             *
             * Instead, we calculate inventory from the current database
             * state:
             *
             * available = quota - sold - reserved
             *
             * Any existing reserved amount belongs to other active
             * holds, not to this expired order.
             */
            $availableQuota =
                (int) $ticketType->quota
                - (int) $ticketType->sold
                - (int) $ticketType->reserved;

            /*
             * ---------------------------------------------------------
             * INVENTORY AVAILABLE
             * ---------------------------------------------------------
             *
             * Reacquire inventory for the late payment.
             *
             * Because the expired order no longer owns a reservation,
             * we only increase sold here.
             */
            if ($availableQuota >= $order->quantity) {
                $ticketType->increment(
                    'sold',
                    $order->quantity
                );

                $order->update([
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'hold_expires_at' => null,
                    'notes' => 'Recovered after late payment capture.',
                ]);

                $order = $order->fresh([
                    'user',
                    'event',
                    'ticketType',
                    'tickets',
                ]);

                app(EventTicketService::class)
                    ->generateTickets($order);

                return $order->fresh([
                    'user',
                    'event',
                    'ticketType',
                    'tickets',
                ]);
            }

            /*
             * ---------------------------------------------------------
             * INVENTORY UNAVAILABLE
             * ---------------------------------------------------------
             *
             * The customer paid successfully, but the original ticket
             * inventory can no longer be reacquired.
             *
             * Do NOT call Xendit while the DB transaction is open.
             *
             * First commit the local state, then request the refund.
             */
            $refundPayment = $payment;
            $refundAmount = (float) $payment->amount;

            $order->update([
                'notes' =>
                    'Late payment captured after order expired; '
                    . 'inventory unavailable and refund requested.',
            ]);

            return $order->fresh([
                'user',
                'event',
                'ticketType',
                'tickets',
            ]);
        });

        /*
         * -------------------------------------------------------------
         * REFUND AFTER COMMIT
         * -------------------------------------------------------------
         *
         * Xendit is an external system. Never call it while the
         * inventory transaction is still open.
         */
        if (
            $refundPayment !== null
            && $refundAmount !== null
        ) {
            $this->paymentRefundService->createRefund(
                $refundPayment,
                $refundAmount,
                'CANCELLATION'
            );

            $order->refresh();
        }

        return $order->fresh([
            'user',
            'event',
            'ticketType',
            'tickets',
        ]);
    }
}