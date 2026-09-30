<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingTicket;
use App\Models\EventTicketOrder;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\PaymentSession;
use App\Services\EventTicketPurchaseService;
use App\Services\EventTicketService;
use App\Services\LatePaymentRecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class XenditWebhookController extends Controller
{
    /**
     * Handle Xendit webhook.
     */
    public function handle(Request $request): JsonResponse
    {
        /*
         * Verify Xendit callback token.
         */
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

            case 'refund.succeeded':
                $this->handleRefundSucceeded($data);
                break;

            case 'refund.failed':
                $this->handleRefundFailed($data);
                break;
        }

        return response()->json([
            'message' => 'Webhook received.',
        ]);
    }

    /**
     * Handle successful payment capture.
     *
     * Important:
     * Payment state is committed first.
     *
     * Booking confirmation / event ticket recovery happens
     * AFTER the payment transaction has been committed.
     *
     * This is important because late payment recovery may need
     * to communicate with Xendit for a refund.
     */
    private function handlePaymentCapture(array $data): void
    {
        $payment = DB::transaction(function () use ($data) {
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
             * Preserve existing provider payload.
             */
            $existingPayload = $payment->provider_payload ?? [];

            $updatedPayload = array_merge(
                $existingPayload,
                [
                    'webhook' => $data,
                ]
            );

            /*
             * Idempotency:
             *
             * If payment is already paid, do not overwrite
             * payment timestamps or provider transaction data.
             *
             * However, we still return the payment so the
             * downstream booking/event recovery can be retried.
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
                 * Keep the latest webhook payload without
                 * changing the original paid timestamp.
                 */
                $payment->update([
                    'provider_transaction_id' =>
                        $payment->provider_transaction_id
                        ?? $xenditPaymentId,

                    'provider_payload' => $updatedPayload,
                ]);
            }

            /*
             * Update payment session.
             */
            $paymentSession = PaymentSession::query()
                ->whereKey($payment->payment_session_id)
                ->lockForUpdate()
                ->first();

            if ($paymentSession) {
                /*
                 * Do not overwrite a payment session that has
                 * already reached a final paid state.
                 */
                if ($paymentSession->status !== 'paid') {
                    $paymentSession->update([
                        'status' => 'paid',
                        'paid_at' => now(),
                    ]);
                }
            }

            return $payment->fresh();
        });

        /*
         * Nothing to process if payment could not be resolved.
         */
        if (!$payment) {
            return;
        }

        /*
         * Normal booking payment.
         *
         * This happens AFTER the payment transaction above
         * has committed.
         */
        if ($payment->booking_id !== null) {
            $this->confirmBooking($payment);
        }

        /*
         * Event ticket payment.
         *
         * LatePaymentRecoveryService handles:
         *
         * HELD -> CONFIRMED
         *
         * EXPIRED + inventory available
         * -> CONFIRMED
         *
         * EXPIRED + inventory unavailable
         * -> REFUND
         */
        if ($payment->event_ticket_order_id !== null) {
            $recoveryService = app(
                LatePaymentRecoveryService::class
            );

            $recoveryService->recoverEventTicketPayment(
                $payment
            );
        }
    }

    /**
     * Confirm normal booking after successful payment.
     */
    private function confirmBooking(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $booking = Booking::query()
                ->whereKey($payment->booking_id)
                ->lockForUpdate()
                ->first();

            if (!$booking) {
                return;
            }

            /*
             * Already confirmed:
             * do not generate another ticket.
             */
            if ($booking->status === 'confirmed') {
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

                return;
            }

            /*
             * Only an active booking hold can be confirmed.
             */
            if ($booking->status !== 'held') {
                return;
            }

            /*
             * Do not confirm an expired booking.
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
             * Confirm booking.
             */
            $booking->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);

            /*
             * Create digital ticket.
             *
             * firstOrCreate makes this operation idempotent.
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
     * Handle payment failure webhook.
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
             * Never overwrite a successful payment.
             */
            if ($payment->status === 'paid') {
                return;
            }

            /*
             * Preserve provider payload.
             */
            $existingPayload = $payment->provider_payload ?? [];

            $updatedPayload = array_merge(
                $existingPayload,
                [
                    'webhook' => $data,
                ]
            );

            /*
             * Mark payment as failed.
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
             * Update payment session.
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
     * Handle successful refund webhook.
     *
     * Xendit sends refund.succeeded when the refund request
     * has been successfully processed by Xendit/payment partner.
     *
     * This does NOT change Payment::status to "refunded".
     * Refund state is stored separately in payment_refunds.
     */
    private function handleRefundSucceeded(array $data): void
    {
        DB::transaction(function () use ($data) {
            $refund = $this->findPaymentRefund($data);

            if (!$refund) {
                return;
            }

            /*
             * Idempotency:
             * If already succeeded, do nothing.
             */
            if ($refund->status === 'succeeded') {
                return;
            }

            $refund->update([
                'provider_refund_id' =>
                    $data['id'] ??
                    $refund->provider_refund_id,

                'status' => 'succeeded',

                'failure_code' => null,
                'failure_reason' => null,

                'refund_fee_amount' =>
                    $data['refund_fee_amount']
                    ?? $refund->refund_fee_amount,

                'provider_payload' => $data,

                'succeeded_at' => now(),
                'failed_at' => null,
            ]);
        });
    }

    /**
     * Handle failed refund webhook.
     */
    private function handleRefundFailed(array $data): void
    {
        DB::transaction(function () use ($data) {
            $refund = $this->findPaymentRefund($data);

            if (!$refund) {
                return;
            }

            /*
             * If refund has already succeeded, never downgrade it.
             */
            if ($refund->status === 'succeeded') {
                return;
            }

            $refund->update([
                'provider_refund_id' =>
                    $data['id'] ??
                    $refund->provider_refund_id,

                'status' => 'failed',

                'failure_code' =>
                    $data['failure_code']
                    ?? $refund->failure_code,

                'failure_reason' =>
                    $data['failure_code']
                    ?? $refund->failure_reason,

                'refund_fee_amount' =>
                    $data['refund_fee_amount']
                    ?? $refund->refund_fee_amount,

                'provider_payload' => $data,

                'failed_at' => now(),
            ]);
        });
    }

    /**
     * Find internal refund record using Xendit identifiers.
     *
     * Priority:
     *
     * 1. Xendit refund ID
     * 2. Merchant reference ID
     */
    private function findPaymentRefund(
        array $data
    ): ?PaymentRefund {
        $providerRefundId = $data['id'] ?? null;
        $referenceId = $data['reference_id'] ?? null;

        /*
         * Prefer Xendit's refund ID.
         */
        if ($providerRefundId) {
            $refund = PaymentRefund::query()
                ->where(
                    'provider_refund_id',
                    $providerRefundId
                )
                ->lockForUpdate()
                ->first();

            if ($refund) {
                return $refund;
            }
        }

        /*
         * Fallback to VYBES internal reference ID.
         */
        if ($referenceId) {
            return PaymentRefund::query()
                ->where(
                    'reference_id',
                    $referenceId
                )
                ->lockForUpdate()
                ->first();
        }

        return null;
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