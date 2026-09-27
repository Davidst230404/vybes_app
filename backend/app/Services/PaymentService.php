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
                'provider' => null,
                'provider_transaction_id' => null,
                'method' => null,
                'status' => 'pending',
                'amount' => $paymentSession->amount,
            ]);
        });
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