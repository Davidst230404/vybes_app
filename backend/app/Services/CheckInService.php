<?php

namespace App\Services;

use App\Models\BookingTicket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckInService
{
    public function checkIn(
        string $qrPayload,
        User $user
    ): BookingTicket {
        return DB::transaction(function () use (
            $qrPayload,
            $user
        ) {

            $payload = json_decode($qrPayload, true);

            if (!is_array($payload)) {
                throw ValidationException::withMessages([
                    'qr_payload' => [
                        'Invalid QR payload.',
                    ],
                ]);
            }

            if (
                ($payload['type'] ?? null) !== 'VYBES_TICKET' ||
                empty($payload['booking_id']) ||
                empty($payload['booking_code'])
            ) {
                throw ValidationException::withMessages([
                    'qr_payload' => [
                        'Invalid VYBES ticket QR code.',
                    ],
                ]);
            }

            $ticket = BookingTicket::query()
                ->with([
                    'booking.venue',
                    'booking.items.resource',
                    'booking.payment',
                ])
                ->where('booking_id', $payload['booking_id'])
                ->lockForUpdate()
                ->first();

            if (!$ticket) {
                throw ValidationException::withMessages([
                    'ticket' => [
                        'Ticket not found.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Validate Merchant
            |--------------------------------------------------------------------------
            */

            $merchant = $user->merchant;

            if (!$merchant) {
                throw ValidationException::withMessages([
                    'merchant' => [
                        'Merchant profile not found.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Validate Merchant Ownership
            |--------------------------------------------------------------------------
            */

            if (
                $ticket->booking->venue->merchant_id !==
                $merchant->id
            ) {
                throw ValidationException::withMessages([
                    'venue' => [
                        'You are not authorized to check in tickets for this venue.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Validate QR Payload
            |--------------------------------------------------------------------------
            */

            if (
                $ticket->booking->booking_code !==
                $payload['booking_code']
            ) {
                throw ValidationException::withMessages([
                    'qr_payload' => [
                        'QR code does not match the ticket.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Validate Ticket
            |--------------------------------------------------------------------------
            */

            if ($ticket->status !== 'active') {
                throw ValidationException::withMessages([
                    'ticket' => [
                        'This ticket is not active.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Validate Booking
            |--------------------------------------------------------------------------
            */

            if ($ticket->booking->status !== 'confirmed') {
                throw ValidationException::withMessages([
                    'booking' => [
                        'This booking is not confirmed.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Validate Payment
            |--------------------------------------------------------------------------
            */

            $payment = $ticket->booking->payment;

            if (!$payment || $payment->status !== 'paid') {
                throw ValidationException::withMessages([
                    'payment' => [
                        'Payment has not been completed.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Validate Booking Date
            |--------------------------------------------------------------------------
            */

            $bookingDate = $ticket->booking->starts_at->toDateString();
            $today = now()->toDateString();

            if ($bookingDate !== $today) {
                throw ValidationException::withMessages([
                    'booking' => [
                        'This ticket can only be checked in on the booking date.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Validate Check-in Status
            |--------------------------------------------------------------------------
            */

            if ($ticket->checked_in_at !== null) {
                throw ValidationException::withMessages([
                    'ticket' => [
                        'This ticket has already been checked in.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Record Check-in
            |--------------------------------------------------------------------------
            */

            $ticket->update([
                'checked_in_at' => now(),
            ]);

            return $ticket->fresh([
                'booking.venue',
                'booking.items.resource',
                'booking.payment',
            ]);
        });
    }
}