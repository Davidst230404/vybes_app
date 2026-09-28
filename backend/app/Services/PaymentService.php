<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function createFromSession(
        PaymentSession $paymentSession
    ): Payment {
        return DB::transaction(function () use ($paymentSession) {
            $paymentSession = PaymentSession::query()
                ->whereKey($paymentSession->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($paymentSession->status !== 'active') {
                throw ValidationException::withMessages([
                    'payment_session' => [
                        'The payment session is not active.',
                    ],
                ]);
            }

            if ($paymentSession->expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'payment_session' => [
                        'The payment session has expired.',
                    ],
                ]);
            }

            $existingPayment = Payment::query()
                ->where('payment_session_id', $paymentSession->id)
                ->first();

            if ($existingPayment) {
                return $existingPayment;
            }

            return Payment::create([
                'booking_id' => $paymentSession->booking_id,
                'payment_session_id' => $paymentSession->id,
                'payment_code' => $this->generatePaymentCode(),
                'provider' => 'xendit',
                'provider_request_id' => null,
                'provider_transaction_id' => null,
                'method' => 'QRIS',
                'status' => 'pending',
                'amount' => $paymentSession->amount,
            ]);
        });
    }

    public function createXenditPayment(
        Payment $payment,
        XenditService $xenditService
    ): Payment {
        $payment = Payment::query()
            ->with([
                'booking',
                'paymentSession',
            ])
            ->whereKey($payment->id)
            ->firstOrFail();

        if ($payment->status !== 'pending') {
            throw ValidationException::withMessages([
                'payment' => [
                    'The payment is not pending.',
                ],
            ]);
        }

        if ($payment->provider_request_id) {
            return $payment;
        }

        $response = $xenditService->createQrisPayment($payment);

        $payment->update([
            'provider' => 'xendit',
            'provider_request_id' => $response['payment_request_id'] ?? null,
            'provider_transaction_id' => $response['latest_payment_id'] ?? null,
            'method' => 'QRIS',
            'provider_payload' => $response,
        ]);

        return $payment->fresh([
            'booking',
            'paymentSession',
        ]);
    }

    private function generatePaymentCode(): string
    {
        do {
            $code = 'VYB-PAY-' . strtoupper(
                substr(bin2hex(random_bytes(5)), 0, 10)
            );
        } while (
            Payment::where('payment_code', $code)->exists()
        );

        return $code;
    }
}