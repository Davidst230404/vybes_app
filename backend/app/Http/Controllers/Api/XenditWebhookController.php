<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingTicket;
use App\Models\EventTicketOrder;
use App\Models\Payment;
use App\Models\PaymentSession;
use App\Services\EventTicketPurchaseService;
use App\Services\EventTicketService;
use App\Services\LatePaymentRecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class XenditWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $callbackToken = $request->header('x-callback-token');
        $expectedToken = config('services.xendit.webhook_token');

        if (
            empty($expectedToken) ||
            !hash_equals(
                $expectedToken,
                (string) $callbackToken
            )
        ) {
            return response()->json([
                'message' => 'Invalid webhook token.',
            ], 403);
        }

        $event = $request->input('event');
        $data = $request->input('data', []);

        switch ($event) {
            case 'payment.capture':
                $this->handlePaymentCapture($data);
                break;

            case 'payment.failure':
                $this->handlePaymentFailure($data);
                break;
        }

        return response()->json([
            'message' => 'Webhook received.',
        ]);
    }

    /**
     * Handle successful Xendit payment capture.
     *
     * IMPORTANT:
     *
     * Payment state is committed first.
     * Business state is processed only AFTER the payment transaction
     * has successfully committed.
     *
     * This is required because LatePaymentRecoveryService may call
     * Xendit refund externally and must never do so while the critical
     * database transaction is still open.
     */
    private function handlePaymentCapture(array $data): void
    {
        $paymentId = DB::transaction(function () use ($data) {
            $paymentRequestId = $data['payment_request_id'] ?? null;
            $xenditPaymentId = $data['payment_id'] ?? null;

            if (!$paymentRequestId) {
                return null;
            }

            $payment = Payment::query()
                ->where('provider', 'xendit')
                ->where(
                    'provider_request_id',
                    $paymentRequestId
                )
                ->lockForUpdate()
                ->first();

            if (!$payment) {
                return null;
            }

            /*
            |--------------------------------------------------------------------------
            | Idempotency
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            |
            | We do NOT return from the entire webhook when payment is already
            | paid.
            |
            | A previous webhook may have successfully committed the payment
            | but failed during the business-state processing afterwards.
            |
            | Therefore the payment can already be "paid" while the related
            | order still needs recovery.
            |
            */

            $existingPayload = $payment->provider_payload ?? [];

            $updatedPayload = array_merge(
                $existingPayload,
                [
                    'webhook' => $data,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Mark payment as paid
            |--------------------------------------------------------------------------
            */

            if ($payment->status !== 'paid') {
                $payment->update([
                    'status' => 'paid',
                    'provider_transaction_id' => $xenditPaymentId,
                    'paid_at' => now(),
                    'provider_payload' => $updatedPayload,
                ]);
            } else {
                /*
                |------------------------------------------------------------------
                | Payment was already marked as paid.
                | Keep the latest webhook payload without changing the
                | payment state again.
                |------------------------------------------------------------------
                */

                $payment->update([
                    'provider_transaction_id' =>
                        $payment->provider_transaction_id
                        ?? $xenditPaymentId,

                    'provider_payload' => $updatedPayload,
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Update payment session
            |--------------------------------------------------------------------------
            */

            if ($payment->payment_session_id !== null) {
                $paymentSession = PaymentSession::query()
                    ->whereKey($payment->payment_session_id)
                    ->lockForUpdate()
                    ->first();

                if ($paymentSession) {
                    $paymentSession->update([
                        'status' => 'paid',
                        'paid_at' => $payment->paid_at ?? now(),
                    ]);
                }
            }

            return $payment->id;
        });

        /*
        |--------------------------------------------------------------------------
        | No matching payment
        |--------------------------------------------------------------------------
        */

        if ($paymentId === null) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | IMPORTANT:
        |
        | The transaction above has now COMMITTED.
        |
        | Only after this point do we process booking/event-ticket business
        | state.
        |--------------------------------------------------------------------------
        */

        $payment = Payment::query()
            ->find($paymentId);

        if (!$payment) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Booking payment
        |--------------------------------------------------------------------------
        */

        if ($payment->booking_id !== null) {
            $this->confirmBooking($payment);
        }

        /*
        |--------------------------------------------------------------------------
        | Event ticket payment
        |--------------------------------------------------------------------------
        */

        if ($payment->event_ticket_order_id !== null) {
            $this->confirmEventTicketOrder($payment);
        }
    }

    /**
     * Confirm normal booking after successful payment.
     */
    private function confirmBooking(Payment $payment): void
    {
        /*
        |--------------------------------------------------------------------------
        | Business transaction
        |--------------------------------------------------------------------------
        |
        | This transaction is completely separate from the payment transaction.
        |
        */

        DB::transaction(function () use ($payment) {
            $booking = Booking::query()
                ->whereKey($payment->booking_id)
                ->lockForUpdate()
                ->first();

            if (!$booking) {
                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Already processed
            |--------------------------------------------------------------------------
            */

            if ($booking->status === 'confirmed') {
                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Only process active hold
            |--------------------------------------------------------------------------
            */

            if ($booking->status !== 'held') {
                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Expired booking
            |--------------------------------------------------------------------------
            */

            if (
                $booking->hold_expires_at !== null &&
                $booking->hold_expires_at->isPast()
            ) {
                $booking->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'notes' => trim(
                        ($booking->notes ?? '') .
                        ' Payment captured after booking hold expired. ' .
                        'Manual refund handling is required.'
                    ),
                ]);

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Confirm booking
            |--------------------------------------------------------------------------
            */

            $booking->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);

            /*
            |--------------------------------------------------------------------------
            | Create digital ticket
            |--------------------------------------------------------------------------
            */

            BookingTicket::firstOrCreate(
                [
                    'booking_id' => $booking->id,
                ],
                [
                    'ticket_code' => $this->generateTicketCode(),
                    'status' => 'active',
                    'qr_payload' => $this->generateQrPayload(
                        $booking
                    ),
                    'issued_at' => now(),
                ]
            );
        });
    }

    /**
     * Confirm event ticket order after successful payment.
     *
     * HELD:
     *      EventTicketPurchaseService handles normal confirmation.
     *
     * EXPIRED:
     *      LatePaymentRecoveryService handles inventory
     *      reacquisition and refund if necessary.
     */
    private function confirmEventTicketOrder(Payment $payment): void
    {
        $order = EventTicketOrder::query()
            ->find($payment->event_ticket_order_id);

        if (!$order) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Already confirmed
        |--------------------------------------------------------------------------
        |
        | Idempotent:
        | Do not increase sold again.
        | Do not create duplicate tickets.
        |
        */

        if ($order->status === 'confirmed') {
            app(EventTicketService::class)
                ->generateTickets($order);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | NORMAL PAYMENT
        |--------------------------------------------------------------------------
        |
        | Payment arrived while the ticket order is still held.
        |
        */

        if ($order->status === 'held') {
            $purchaseService = app(
                EventTicketPurchaseService::class
            );

            $order = $purchaseService->confirmOrder(
                $order
            );

            /*
            |--------------------------------------------------------------------------
            | Generate individual event tickets
            |--------------------------------------------------------------------------
            */

            if ($order->status === 'confirmed') {
                app(EventTicketService::class)
                    ->generateTickets($order);
            }

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | LATE PAYMENT
        |--------------------------------------------------------------------------
        |
        | Payment arrived after the original ticket hold expired.
        |
        | IMPORTANT:
        |
        | LatePaymentRecoveryService owns this flow.
        |
        | It will:
        |
        | 1. Lock order + ticket type.
        | 2. Reconcile current inventory.
        | 3. Reacquire inventory if possible.
        | 4. Confirm order + generate tickets.
        | 5. Otherwise commit DB state first.
        | 6. Then request Xendit refund.
        |
        */

        if ($order->status === 'expired') {
            app(LatePaymentRecoveryService::class)
                ->recoverEventTicketPayment($payment);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Other terminal states
        |--------------------------------------------------------------------------
        |
        | cancelled / refunded / etc.
        |
        | Nothing to do here.
        |
        */

        return;
    }

    /**
     * Handle failed Xendit payment.
     */
    private function handlePaymentFailure(array $data): void
    {
        DB::transaction(function () use ($data) {
            $paymentRequestId = $data['payment_request_id'] ?? null;

            if (!$paymentRequestId) {
                return;
            }

            $payment = Payment::query()
                ->where('provider', 'xendit')
                ->where(
                    'provider_request_id',
                    $paymentRequestId
                )
                ->lockForUpdate()
                ->first();

            if (!$payment) {
                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Never overwrite successful payment
            |--------------------------------------------------------------------------
            */

            if ($payment->status === 'paid') {
                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Preserve provider payload
            |--------------------------------------------------------------------------
            */

            $existingPayload = $payment->provider_payload ?? [];

            $updatedPayload = array_merge(
                $existingPayload,
                [
                    'webhook' => $data,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Mark payment failed
            |--------------------------------------------------------------------------
            */

            $payment->update([
                'status' => 'failed',
                'failed_at' => now(),
                'failure_reason' =>
                    $data['failure_code'] ??
                    'Payment failed.',
                'provider_payload' => $updatedPayload,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Update payment session
            |--------------------------------------------------------------------------
            */

            if ($payment->payment_session_id !== null) {
                $paymentSession = PaymentSession::query()
                    ->whereKey($payment->payment_session_id)
                    ->lockForUpdate()
                    ->first();

                if ($paymentSession) {
                    $paymentSession->update([
                        'status' => 'failed',
                    ]);
                }
            }
        });
    }

    /**
     * Generate unique booking ticket code.
     */
    private function generateTicketCode(): string
    {
        do {
            $code = 'VYB-TKT-' . strtoupper(
                substr(
                    bin2hex(random_bytes(5)),
                    0,
                    10
                )
            );
        } while (
            BookingTicket::where(
                'ticket_code',
                $code
            )->exists()
        );

        return $code;
    }

    /**
     * Generate booking QR payload.
     */
    private function generateQrPayload(
        Booking $booking
    ): string {
        return json_encode([
            'type' => 'VYBES_TICKET',
            'booking_id' => $booking->id,
            'booking_code' => $booking->booking_code,
        ]);
    }
}