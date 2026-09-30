<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentRefund;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaymentRefundService
{
    public function __construct(
        private XenditService $xenditService
    ) {
    }

    /**
     * Create and submit a full or partial refund for a paid payment.
     *
     * Database transaction hanya digunakan untuk:
     * - lock payment
     * - validasi payment
     * - mencegah duplicate refund
     * - membuat internal refund record
     *
     * HTTP request ke Xendit dilakukan SETELAH transaction selesai.
     */
    public function createRefund(
        Payment $payment,
        ?float $amount = null,
        string $reason = 'DUPLICATE'
    ): PaymentRefund {
        /*
         * STEP 1
         * Create internal refund record inside DB transaction.
         */
        $refund = DB::transaction(function () use (
            $payment,
            $amount,
            $reason
        ) {
            $payment = Payment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($payment->provider !== 'xendit') {
                throw new RuntimeException(
                    'Refund is only supported for Xendit payments.'
                );
            }

            if ($payment->status !== 'paid') {
                throw new RuntimeException(
                    'Only paid payments can be refunded.'
                );
            }

            $refundAmount = $amount ?? (float) $payment->amount;

            if ($refundAmount <= 0) {
                throw new RuntimeException(
                    'Refund amount must be greater than zero.'
                );
            }

            if ($refundAmount > (float) $payment->amount) {
                throw new RuntimeException(
                    'Refund amount cannot exceed the payment amount.'
                );
            }

            /*
             * Prevent duplicate active/successful refund
             * for the same payment.
             */
            $existingRefund = PaymentRefund::query()
                ->where('payment_id', $payment->id)
                ->whereIn('status', [
                    'pending',
                    'succeeded',
                ])
                ->latest('id')
                ->first();

            if ($existingRefund) {
                return $existingRefund;
            }

            $referenceId = $this->generateReferenceId();

            return PaymentRefund::create([
                'payment_id' => $payment->id,
                'provider' => 'xendit',
                'reference_id' => $referenceId,
                'amount' => $refundAmount,
                'currency' => 'IDR',
                'status' => 'pending',
                'reason' => $reason,
                'requested_at' => now(),
            ]);
        });

        /*
         * STEP 2
         * If refund already exists, do not submit another request.
         */
        if (
            $refund->status === 'succeeded'
            || $refund->status === 'pending'
            && $refund->provider_refund_id !== null
        ) {
            return $refund->fresh();
        }

        /*
         * STEP 3
         * Load payment outside the previous transaction.
         */
        $payment = Payment::query()
            ->whereKey($refund->payment_id)
            ->firstOrFail();

        /*
         * STEP 4
         * Call Xendit OUTSIDE the database transaction.
         */
        try {
            $response = $this->xenditService->createRefund(
                payment: $payment,
                amount: (float) $refund->amount,
                reason: $reason,
                referenceId: $refund->reference_id
            );

            $status = $this->normalizeStatus(
                $response['status'] ?? 'PENDING'
            );

            $refund->update([
                'provider_refund_id' => $response['id'] ?? null,
                'status' => $status,
                'failure_code' => $response['failure_code'] ?? null,
                'failure_reason' => $response['failure_reason'] ?? null,
                'refund_fee_amount' => $response['refund_fee_amount'] ?? null,
                'provider_payload' => $response,
                'succeeded_at' => $status === 'succeeded'
                    ? now()
                    : null,
                'failed_at' => $status === 'failed'
                    ? now()
                    : null,
            ]);

            return $refund->fresh();
        } catch (\Throwable $exception) {
            /*
             * Xendit request failed before a successful refund
             * response was received.
             */
            $refund->update([
                'status' => 'failed',
                'failure_reason' => $exception->getMessage(),
                'failed_at' => now(),
            ]);

            throw $exception;
        }
    }

    /**
     * Generate unique internal refund reference.
     */
    private function generateReferenceId(): string
    {
        return 'VYB-REF-' .
            strtoupper(
                bin2hex(random_bytes(5))
            );
    }

    /**
     * Normalize Xendit refund status.
     */
    private function normalizeStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'PENDING' => 'pending',
            'SUCCEEDED' => 'succeeded',
            'FAILED' => 'failed',
            'CANCELLED' => 'cancelled',
            default => strtolower($status),
        };
    }
}