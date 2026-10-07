<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\EventTicketOrder;
use App\Models\PaymentSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentSessionService
{
    /**
     * Create payment session for a regular booking.
     */
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

            $holdDuration = (int) \App\Models\PlatformSetting::get('booking_hold_duration_minutes', 15);
            $paymentExpiresAt = now()->addMinutes($holdDuration);

            if (
                $booking->hold_expires_at !== null &&
                $booking->hold_expires_at->lt($paymentExpiresAt)
            ) {
                $paymentExpiresAt = $booking->hold_expires_at;
            }

            return PaymentSession::create([
                'booking_id' => $booking->id,
                'event_ticket_order_id' => null,
                'session_code' => $this->generateSessionCode(),
                'status' => 'active',
                'amount' => $booking->total_amount,
                'started_at' => now(),
                'expires_at' => $paymentExpiresAt,
            ]);
        });
    }

    /**
     * Create payment session for an event ticket order.
     */
    public function createForEventTicket(
        EventTicketOrder $order
    ): PaymentSession {
        return DB::transaction(function () use ($order) {

            $order = EventTicketOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status !== 'held') {
                throw ValidationException::withMessages([
                    'order' => [
                        'The ticket order is not available for payment.',
                    ],
                ]);
            }

            if (
                $order->hold_expires_at !== null &&
                $order->hold_expires_at->isPast()
            ) {
                throw ValidationException::withMessages([
                    'order' => [
                        'The ticket order hold has expired.',
                    ],
                ]);
            }

            $existingSession = PaymentSession::query()
                ->where('event_ticket_order_id', $order->id)
                ->first();

            if ($existingSession) {
                return $existingSession;
            }

            $holdDuration = (int) \App\Models\PlatformSetting::get('booking_hold_duration_minutes', 15);
            $paymentExpiresAt = now()->addMinutes($holdDuration);

            if (
                $order->hold_expires_at !== null &&
                $order->hold_expires_at->lt($paymentExpiresAt)
            ) {
                $paymentExpiresAt = $order->hold_expires_at;
            }

            return PaymentSession::create([
                'booking_id' => null,
                'event_ticket_order_id' => $order->id,
                'session_code' => $this->generateSessionCode(),
                'status' => 'active',
                'amount' => $order->total_amount,
                'started_at' => now(),
                'expires_at' => $paymentExpiresAt,
            ]);
        });
    }

    /**
     * Generate unique payment session code.
     */
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