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

    private function handlePaymentCapture(array $data): void
    {
        DB::transaction(function () use ($data) {
            $paymentRequestId = $data['payment_request_id'] ?? null;
            $xenditPaymentId = $data['payment_id'] ?? null;

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
            | Idempotency
            |--------------------------------------------------------------------------
            */

            if ($payment->status === 'paid') {
                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Preserve existing provider payload
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
            | Mark payment as paid
            |--------------------------------------------------------------------------
            */

            $payment->update([
                'status' => 'paid',
                'provider_transaction_id' => $xenditPaymentId,
                'paid_at' => now(),
                'provider_payload' => $updatedPayload,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Update payment session
            |--------------------------------------------------------------------------
            */

            $paymentSession = PaymentSession::query()
                ->whereKey($payment->payment_session_id)
                ->lockForUpdate()
                ->first();

            if ($paymentSession) {
                $paymentSession->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                ]);
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
        });
    }

    /**
     * Confirm normal booking after successful payment.
     */
    private function confirmBooking(Payment $payment): void
    {
        $booking = Booking::query()
            ->whereKey($payment->booking_id)
            ->lockForUpdate()
            ->first();

        if (!$booking) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Only confirm an active booking hold
        |--------------------------------------------------------------------------
        */

        if ($booking->status !== 'held') {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Do not confirm an expired booking
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
    }

    /**
     * Confirm event ticket order after successful payment
     * and generate individual event tickets.
     */
    private function confirmEventTicketOrder(Payment $payment): void
    {
        $order = EventTicketOrder::query()
            ->whereKey($payment->event_ticket_order_id)
            ->lockForUpdate()
            ->first();

        if (!$order) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Confirm order
        |--------------------------------------------------------------------------
        */

        if ($order->status === 'held') {
            $purchaseService = app(EventTicketPurchaseService::class);

            $order = $purchaseService->confirmOrder($order);
        }

        /*
        |--------------------------------------------------------------------------
        | Generate individual event tickets
        |--------------------------------------------------------------------------
        */

        if ($order->status !== 'confirmed') {
            return;
        }

        $ticketService = app(EventTicketService::class);

        $ticketService->generateTickets($order);
    }

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
            | Do not overwrite a successful payment
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
            | Mark payment as failed
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

            $paymentSession = PaymentSession::query()
                ->whereKey($payment->payment_session_id)
                ->lockForUpdate()
                ->first();

            if ($paymentSession) {
                $paymentSession->update([
                    'status' => 'failed',
                ]);
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
                substr(bin2hex(random_bytes(5)), 0, 10)
            );
        } while (
            BookingTicket::where('ticket_code', $code)->exists()
        );

        return $code;
    }

    /**
     * Generate booking QR payload.
     */
    private function generateQrPayload(Booking $booking): string
    {
        return json_encode([
            'type' => 'VYBES_TICKET',
            'booking_id' => $booking->id,
            'booking_code' => $booking->booking_code,
        ]);
    }
}