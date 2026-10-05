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
     * Receive and process Xendit webhook.
     */
    public function handle(Request $request): JsonResponse
    {
        /*
         * ==============================================================
         * 1. VERIFY CALLBACK TOKEN
         * ==============================================================
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

        /*
         * ==============================================================
         * 2. VALIDATE BASIC WEBHOOK STRUCTURE
         * ==============================================================
         *
         * Prevent malformed payloads from reaching business logic.
         */
        $validated = $request->validate([
            'event' => [
                'required',
                'string',
                'max:100',
            ],

            'data' => [
                'required',
                'array',
            ],
        ]);

        $event = $validated['event'];
        $data = $validated['data'];

        /*
         * ==============================================================
         * 3. ONLY PROCESS EXPLICITLY SUPPORTED EVENTS
         * ==============================================================
         */
        $supportedEvents = [
            'payment.capture',
            'payment.failure',
            'refund.succeeded',
            'refund.failed',
        ];

        /*
         * Unknown events are acknowledged safely.
         *
         * We do not allow an arbitrary event value to reach
         * business logic.
         */
        if (
            !in_array(
                $event,
                $supportedEvents,
                true
            )
        ) {
            return response()->json([
                'message' => 'Webhook event ignored.',
            ]);
        }

        /*
         * ==============================================================
         * 4. ROUTE EVENT TO SPECIFIC HANDLER
         * ==============================================================
         */
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
     * Handle successful Xendit payment capture.
     *
     * IMPORTANT:
     *
     * Payment state is committed first.
     *
     * Business state is processed only AFTER the payment transaction
     * has successfully committed.
     *
     * This prevents LatePaymentRecoveryService from making an external
     * Xendit refund request while the critical DB transaction is open.
     */
    private function handlePaymentCapture(array $data): void
    {
        $paymentId = DB::transaction(function () use ($data) {
            $paymentRequestId =
                $data['payment_request_id'] ?? null;

            $xenditPaymentId =
                $data['payment_id'] ?? null;

            $xenditStatus =
                $data['status'] ?? null;

            /*
             * payment.capture is only accepted as successful when
             * the provider status is explicitly SUCCEEDED.
             */
            if (
                !$paymentRequestId ||
                !$xenditPaymentId ||
                $xenditStatus !== 'SUCCEEDED'
            ) {
                return null;
            }

            /*
             * Match the webhook to our local payment request.
             */
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
             * ==========================================================
             * IDEMPOTENCY
             * ==========================================================
             *
             * Do NOT stop the entire webhook simply because payment is
             * already paid.
             *
             * A previous webhook may have successfully committed the
             * payment but failed while processing the related order.
             *
             * Replaying the webhook must therefore be able to continue
             * business-state recovery.
             */
            $existingPayload =
                $payment->provider_payload ?? [];

            $updatedPayload = array_merge(
                $existingPayload,
                [
                    'webhook' => $data,
                ]
            );

            /*
             * ==========================================================
             * MARK PAYMENT AS PAID
             * ==========================================================
             */
            if ($payment->status !== 'paid') {
                $payment->update([
                    'status' => 'paid',

                    'provider_transaction_id' =>
                        $xenditPaymentId,

                    'paid_at' => now(),

                    'provider_payload' =>
                        $updatedPayload,
                ]);
            } else {
                /*
                 * Payment is already paid.
                 *
                 * Keep the transaction ID and latest webhook payload.
                 */
                $payment->update([
                    'provider_transaction_id' =>
                        $payment->provider_transaction_id
                        ?? $xenditPaymentId,

                    'provider_payload' =>
                        $updatedPayload,
                ]);
            }

            /*
             * ==========================================================
             * UPDATE PAYMENT SESSION
             * ==========================================================
             */
            if (
                $payment->payment_session_id !== null
            ) {
                $paymentSession = PaymentSession::query()
                    ->whereKey(
                        $payment->payment_session_id
                    )
                    ->lockForUpdate()
                    ->first();

                if ($paymentSession) {
                    $paymentSession->update([
                        'status' => 'paid',

                        'paid_at' =>
                            $payment->paid_at ?? now(),
                    ]);
                }
            }

            return $payment->id;
        });

        /*
         * No matching local payment.
         */
        if ($paymentId === null) {
            return;
        }

        /*
         * ==============================================================
         * IMPORTANT:
         *
         * The payment DB transaction has already COMMITTED here.
         *
         * Only now process business state.
         * ==============================================================
         */
        $payment = Payment::query()
            ->find($paymentId);

        if (!$payment) {
            return;
        }

        /*
         * Normal booking payment.
         */
        if ($payment->booking_id !== null) {
            $this->confirmBooking($payment);
        }

        /*
         * Event ticket payment.
         */
        if ($payment->event_ticket_order_id !== null) {
            $this->confirmEventTicketOrder($payment);
        }
    }

    /**
     * Confirm normal booking after successful payment.
     */
    private function confirmBooking(
        Payment $payment
    ): void {
        DB::transaction(function () use ($payment) {
            $booking = Booking::query()
                ->whereKey($payment->booking_id)
                ->lockForUpdate()
                ->first();

            if (!$booking) {
                return;
            }

            /*
             * Already confirmed.
             */
            if ($booking->status === 'confirmed') {
                return;
            }

            /*
             * Only active held booking can be confirmed here.
             */
            if ($booking->status !== 'held') {
                return;
            }

            /*
             * Payment may arrive after booking hold expired.
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
             * Create one digital ticket idempotently.
             */
            BookingTicket::firstOrCreate(
                [
                    'booking_id' => $booking->id,
                ],
                [
                    'ticket_code' =>
                        $this->generateTicketCode(),

                    'status' => 'active',

                    'qr_payload' =>
                        $this->generateQrPayload(
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
     *      Normal confirmation.
     *
     * EXPIRED:
     *      Late payment recovery.
     */
    private function confirmEventTicketOrder(
        Payment $payment
    ): void {
        $order = EventTicketOrder::query()
            ->find(
                $payment->event_ticket_order_id
            );

        if (!$order) {
            return;
        }

        /*
         * ==============================================================
         * ALREADY CONFIRMED
         * ==============================================================
         *
         * Idempotent:
         *
         * - no additional sold increment
         * - no duplicate ticket issuance
         */
        if ($order->status === 'confirmed') {
            app(EventTicketService::class)
                ->generateTickets($order);

            return;
        }

        /*
         * ==============================================================
         * NORMAL PAYMENT
         * ==============================================================
         */
        if ($order->status === 'held') {
            $purchaseService = app(
                EventTicketPurchaseService::class
            );

            $order = $purchaseService->confirmOrder(
                $order
            );

            if (
                $order->status === 'confirmed'
            ) {
                app(EventTicketService::class)
                    ->generateTickets($order);
            }

            return;
        }

        /*
         * ==============================================================
         * LATE PAYMENT
         * ==============================================================
         *
         * Payment arrived after original hold expired.
         *
         * LatePaymentRecoveryService owns:
         *
         * 1. locking order + ticket type
         * 2. inventory reconciliation
         * 3. inventory reacquisition
         * 4. order confirmation
         * 5. ticket generation
         * 6. refund when inventory is unavailable
         */
        if ($order->status === 'expired') {
            app(LatePaymentRecoveryService::class)
                ->recoverEventTicketPayment(
                    $payment
                );

            return;
        }

        /*
         * Other terminal states:
         *
         * cancelled / refunded / etc.
         *
         * Nothing to do.
         */
    }

    /**
     * Handle failed Xendit payment.
     */
    private function handlePaymentFailure(
        array $data
    ): void {
        DB::transaction(function () use ($data) {
            $paymentRequestId =
                $data['payment_request_id'] ?? null;

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
            $existingPayload =
                $payment->provider_payload ?? [];

            $updatedPayload = array_merge(
                $existingPayload,
                [
                    'webhook' => $data,
                ]
            );

            /*
             * Mark payment failed.
             */
            $payment->update([
                'status' => 'failed',

                'failed_at' => now(),

                'failure_reason' =>
                    $data['failure_code']
                    ?? 'Payment failed.',

                'provider_payload' =>
                    $updatedPayload,
            ]);

            /*
             * Update payment session.
             */
            if (
                $payment->payment_session_id !== null
            ) {
                $paymentSession = PaymentSession::query()
                    ->whereKey(
                        $payment->payment_session_id
                    )
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
     * Handle successful Xendit refund webhook.
     *
     * Idempotent.
     */
    private function handleRefundSucceeded(
        array $data
    ): void {
        DB::transaction(function () use ($data) {
            $paymentRefund =
                $this->findPaymentRefundForWebhook(
                    $data
                );

            if (!$paymentRefund) {
                return;
            }

            /*
             * Never downgrade succeeded refund.
             *
             * This also protects against out-of-order webhooks.
             */
            if (
                $paymentRefund->status === 'succeeded'
            ) {
                $existingPayload =
                    $paymentRefund->provider_payload
                    ?? [];

                $paymentRefund->update([
                    'provider_payload' =>
                        array_merge(
                            $existingPayload,
                            [
                                'webhook' => $data,
                            ]
                        ),
                ]);

                return;
            }

            $existingPayload =
                $paymentRefund->provider_payload
                ?? [];

            $updatedPayload = array_merge(
                $existingPayload,
                [
                    'webhook' => $data,
                ]
            );

            $paymentRefund->update([
                'provider_refund_id' =>
                    $paymentRefund->provider_refund_id
                    ?? (
                        $data['id']
                        ?? $data['refund_id']
                        ?? null
                    ),

                'status' => 'succeeded',

                'provider_payload' =>
                    $updatedPayload,

                'succeeded_at' =>
                    $paymentRefund->succeeded_at
                    ?? now(),

                'failed_at' => null,

                'failure_code' => null,

                'failure_reason' => null,
            ]);
        });
    }

    /**
     * Handle failed Xendit refund webhook.
     *
     * Idempotent.
     */
    private function handleRefundFailed(
        array $data
    ): void {
        DB::transaction(function () use ($data) {
            $paymentRefund =
                $this->findPaymentRefundForWebhook(
                    $data
                );

            if (!$paymentRefund) {
                return;
            }

            /*
             * Never downgrade a successful refund.
             */
            if (
                $paymentRefund->status === 'succeeded'
            ) {
                $existingPayload =
                    $paymentRefund->provider_payload
                    ?? [];

                $paymentRefund->update([
                    'provider_payload' =>
                        array_merge(
                            $existingPayload,
                            [
                                'webhook' => $data,
                            ]
                        ),
                ]);

                return;
            }

            $existingPayload =
                $paymentRefund->provider_payload
                ?? [];

            $updatedPayload = array_merge(
                $existingPayload,
                [
                    'webhook' => $data,
                ]
            );

            $paymentRefund->update([
                'provider_refund_id' =>
                    $paymentRefund->provider_refund_id
                    ?? (
                        $data['id']
                        ?? $data['refund_id']
                        ?? null
                    ),

                'status' => 'failed',

                'provider_payload' =>
                    $updatedPayload,

                'failure_code' =>
                    $data['failure_code']
                    ?? $data['code']
                    ?? null,

                'failure_reason' =>
                    $data['failure_reason']
                    ?? $data['message']
                    ?? 'Refund failed.',

                'failed_at' =>
                    $paymentRefund->failed_at
                    ?? now(),

                'succeeded_at' => null,
            ]);
        });
    }

    /**
     * Find local PaymentRefund related to webhook.
     *
     * Match priority:
     *
     * 1. provider_refund_id
     * 2. reference_id
     */
    private function findPaymentRefundForWebhook(
        array $data
    ): ?PaymentRefund {
        $providerRefundId =
            $data['id']
            ?? $data['refund_id']
            ?? null;

        $referenceId =
            $data['reference_id']
            ?? null;

        /*
         * ==============================================================
         * MATCH BY PROVIDER REFUND ID
         * ==============================================================
         */
        if ($providerRefundId) {
            $refund = PaymentRefund::query()
                ->where(
                    'provider',
                    'xendit'
                )
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
         * ==============================================================
         * MATCH BY OUR REFERENCE ID
         * ==============================================================
         */
        if ($referenceId) {
            return PaymentRefund::query()
                ->where(
                    'provider',
                    'xendit'
                )
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
                    bin2hex(
                        random_bytes(5)
                    ),
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
            'type' =>
                'VYBES_TICKET',

            'booking_id' =>
                $booking->id,

            'booking_code' =>
                $booking->booking_code,
        ]);
    }
}