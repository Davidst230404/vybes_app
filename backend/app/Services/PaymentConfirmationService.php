<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentConfirmationService
{
    public function confirm(
        Payment $payment,
        string $method = 'simulation'
    ): Payment {
        return DB::transaction(function () use ($payment, $method) {
            $payment = Payment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($payment->status === 'paid') {
                return $payment->load([
                    'booking',
                    'paymentSession',
                ]);
            }

            if ($payment->status !== 'pending') {
                throw ValidationException::withMessages([
                    'payment' => [
                        'The payment cannot be confirmed.',
                    ],
                ]);
            }

            $paymentSession = $payment->paymentSession;

            if (!$paymentSession) {
                throw ValidationException::withMessages([
                    'payment_session' => [
                        'Payment session not found.',
                    ],
                ]);
            }

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

            $booking = $payment->booking;

            if ($booking->status !== 'held') {
                throw ValidationException::withMessages([
                    'booking' => [
                        'The booking is not available for confirmation.',
                    ],
                ]);
            }

            if (
                $booking->hold_expires_at !== null &&
                $booking->hold_expires_at->isPast()
            ) {
                throw ValidationException::withMessages([
                    'booking' => [
                        'The booking hold has expired.',
                    ],
                ]);
            }

            $payment->update([
                'status' => 'paid',
                'method' => $method,
                'paid_at' => now(),
            ]);

            $paymentSession->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);

            $booking->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);

            return $payment->fresh([
                'booking',
                'paymentSession',
            ]);
        });
    }
}