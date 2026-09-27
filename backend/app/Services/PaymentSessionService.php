<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\PaymentSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentSessionService
{
    public function create(Booking $booking): PaymentSession
    {
        return DB::transaction(function () use ($booking) {
            $booking = Booking::query()
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($booking->status !== 'held') {
                throw ValidationException::withMessages([
                    'booking' => [
                        'The booking is not available for payment.',
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

            $existingSession = PaymentSession::query()
                ->where('booking_id', $booking->id)
                ->first();

            if ($existingSession) {
                return $existingSession;
            }

            $paymentExpiresAt = now()->addMinutes(15);

            if (
                $booking->hold_expires_at !== null &&
                $booking->hold_expires_at->lt($paymentExpiresAt)
            ) {
                $paymentExpiresAt = $booking->hold_expires_at;
            }

            return PaymentSession::create([
                'booking_id' => $booking->id,
                'session_code' => $this->generateSessionCode(),
                'status' => 'active',
                'amount' => $booking->total_amount,
                'started_at' => now(),
                'expires_at' => $paymentExpiresAt,
            ]);
        });
    }

    private function generateSessionCode(): string
    {
        do {
            $code = 'PAY-' . strtoupper(
                substr(bin2hex(random_bytes(5)), 0, 10)
            );
        } while (
            PaymentSession::where('session_code', $code)->exists()
        );

        return $code;
    }
}