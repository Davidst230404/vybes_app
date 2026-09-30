<?php

namespace App\Services;

use App\Models\BookingTicket;
use App\Models\EventTicket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckInService
{
    /**
     * Process check-in untuk:
     * - Booking ticket oleh Merchant
     * - Event ticket oleh Organizer
     */
    public function checkIn(
        string $qrPayload,
        User $user
    ): BookingTicket|EventTicket {
        /*
         * Event QR menggunakan format:
         *
         * v1.{base64url(payload)}.{signature}
         *
         * Booking QR lama masih menggunakan JSON.
         */
        if ($this->isSignedEventQr($qrPayload)) {
            return $this->checkInEventTicket(
                $qrPayload,
                $user
            );
        }

        return $this->checkInBookingTicket(
            $qrPayload,
            $user
        );
    }

    /**
     * Detect signed event ticket QR.
     */
    private function isSignedEventQr(string $qrPayload): bool
    {
        return str_starts_with($qrPayload, 'v1.');
    }

    /**
     * Check-in booking ticket.
     *
     * Flow existing booking dipertahankan.
     */
    private function checkInBookingTicket(
        string $qrPayload,
        User $user
    ): BookingTicket {
        return DB::transaction(function () use (
            $qrPayload,
            $user
        ) {
            /*
             * Booking QR masih menggunakan JSON.
             */
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

            /*
             * Pastikan hanya merchant yang dapat melakukan
             * booking check-in.
             */
            if (!$user->hasRole('merchant')) {
                throw ValidationException::withMessages([
                    'user' => [
                        'Only merchants can check in booking tickets.',
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
             * Validate Merchant.
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
             * Validate Merchant Ownership.
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
             * Validate QR Payload.
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
             * Validate Ticket.
             */
            if ($ticket->status !== 'active') {
                throw ValidationException::withMessages([
                    'ticket' => [
                        'This ticket is not active.',
                    ],
                ]);
            }

            /*
             * Validate Booking.
             */
            if ($ticket->booking->status !== 'confirmed') {
                throw ValidationException::withMessages([
                    'booking' => [
                        'This booking is not confirmed.',
                    ],
                ]);
            }

            /*
             * Validate Payment.
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
             * Validate Booking Date.
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
             * Validate Check-in Status.
             */
            if ($ticket->checked_in_at !== null) {
                throw ValidationException::withMessages([
                    'ticket' => [
                        'This ticket has already been checked in.',
                    ],
                ]);
            }

            /*
             * Record Check-in.
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

    /**
     * Check-in event ticket.
     *
     * Hanya Organizer dari event tersebut yang boleh melakukan
     * check-in.
     */
    private function checkInEventTicket(
        string $qrPayload,
        User $user
    ): EventTicket {
        return DB::transaction(function () use (
            $qrPayload,
            $user
        ) {
            /*
             * Validate dan decode signed QR.
             */
            $payload = $this->decodeAndVerifyEventQr(
                $qrPayload
            );

            /*
             * Pastikan user adalah organizer.
             */
            if (!$user->hasRole('organizer')) {
                throw ValidationException::withMessages([
                    'user' => [
                        'Only organizers can check in event tickets.',
                    ],
                ]);
            }

            /*
             * ticket_code adalah identifier unik per ticket.
             */
            $ticket = EventTicket::query()
                ->with([
                    'order.payment',
                    'event',
                ])
                ->where('ticket_code', $payload['ticket_code'])
                ->lockForUpdate()
                ->first();

            if (!$ticket) {
                throw ValidationException::withMessages([
                    'ticket' => [
                        'Event ticket not found.',
                    ],
                ]);
            }

            /*
             * Pastikan QR benar-benar milik ticket tersebut.
             */
            if (
                $ticket->event_ticket_order_id !==
                $payload['order_id']
            ) {
                throw ValidationException::withMessages([
                    'qr_payload' => [
                        'QR code does not match the ticket.',
                    ],
                ]);
            }

            if (
                $ticket->event_id !==
                $payload['event_id']
            ) {
                throw ValidationException::withMessages([
                    'qr_payload' => [
                        'QR code does not match the event.',
                    ],
                ]);
            }

            /*
             * Validate Organizer Ownership.
             */
            if (
                !$ticket->event ||
                $ticket->event->organizer_id !==
                $user->organizer?->id
            ) {
                throw ValidationException::withMessages([
                    'event' => [
                        'You are not authorized to check in tickets for this event.',
                    ],
                ]);
            }

            /*
             * Validate Ticket Status.
             */
            if ($ticket->status !== 'active') {
                throw ValidationException::withMessages([
                    'ticket' => [
                        'This event ticket is not active.',
                    ],
                ]);
            }

            /*
             * Validate Order.
             */
            if (!$ticket->order) {
                throw ValidationException::withMessages([
                    'order' => [
                        'Event ticket order not found.',
                    ],
                ]);
            }

            if ($ticket->order->status !== 'confirmed') {
                throw ValidationException::withMessages([
                    'order' => [
                        'This event ticket order is not confirmed.',
                    ],
                ]);
            }

            /*
             * Validate Payment.
             */
            $payment = $ticket->order->payment;

            if (!$payment || $payment->status !== 'paid') {
                throw ValidationException::withMessages([
                    'payment' => [
                        'Payment has not been completed.',
                    ],
                ]);
            }

            /*
             * Validate Event Date.
             *
             * Check-in hanya boleh dilakukan pada tanggal event.
             */
            if (!$ticket->event->starts_at) {
                throw ValidationException::withMessages([
                    'event' => [
                        'Event start date is not configured.',
                    ],
                ]);
            }

            $eventDate = $ticket->event->starts_at->toDateString();
            $today = now()->toDateString();

            if ($eventDate !== $today) {
                throw ValidationException::withMessages([
                    'event' => [
                        'This ticket can only be checked in on the event date.',
                    ],
                ]);
            }

            /*
             * Prevent duplicate check-in.
             */
            if ($ticket->checked_in_at !== null) {
                throw ValidationException::withMessages([
                    'ticket' => [
                        'This event ticket has already been checked in.',
                    ],
                ]);
            }

            /*
             * Record check-in.
             */
            $ticket->update([
                'checked_in_at' => now(),
            ]);

            return $ticket->fresh([
                'order.payment',
                'event',
            ]);
        });
    }

    /**
     * Decode dan verify signed event QR.
     */
    private function decodeAndVerifyEventQr(
        string $qrPayload
    ): array {
        $parts = explode('.', $qrPayload);

        if (count($parts) !== 3) {
            throw ValidationException::withMessages([
                'qr_payload' => [
                    'Invalid signed event ticket QR code.',
                ],
            ]);
        }

        [$version, $encodedPayload, $signature] = $parts;

        if ($version !== 'v1') {
            throw ValidationException::withMessages([
                'qr_payload' => [
                    'Unsupported QR version.',
                ],
            ]);
        }

        if ($encodedPayload === '' || $signature === '') {
            throw ValidationException::withMessages([
                'qr_payload' => [
                    'Invalid signed event ticket QR code.',
                ],
            ]);
        }

        /*
         * Recalculate signature dari payload.
         */
        $expectedSignature = hash_hmac(
            'sha256',
            $encodedPayload,
            $this->qrSigningKey()
        );

        /*
         * Timing-safe comparison.
         */
        if (!hash_equals($expectedSignature, $signature)) {
            throw ValidationException::withMessages([
                'qr_payload' => [
                    'Invalid QR signature.',
                ],
            ]);
        }

        /*
         * Decode Base64 URL-safe.
         */
        $json = base64_decode(
            strtr(
                $encodedPayload,
                '-_',
                '+/'
            ),
            true
        );

        if ($json === false) {
            throw ValidationException::withMessages([
                'qr_payload' => [
                    'Invalid QR payload encoding.',
                ],
            ]);
        }

        /*
         * Tambahkan padding Base64 jika diperlukan.
         */
        $padding = strlen($json) % 4;

        if ($padding !== 0) {
            $json .= str_repeat('=', 4 - $padding);
        }

        /*
         * Decode JSON.
         */
        $payload = json_decode($json, true);

        if (!is_array($payload)) {
            throw ValidationException::withMessages([
                'qr_payload' => [
                    'Invalid QR payload.',
                ],
            ]);
        }

        /*
         * Validate required event QR fields.
         */
        if (
            ($payload['type'] ?? null) !==
                'VYBES_EVENT_TICKET' ||
            ($payload['version'] ?? null) !== 1 ||
            empty($payload['ticket_code']) ||
            empty($payload['order_id']) ||
            empty($payload['order_code']) ||
            empty($payload['event_id']) ||
            empty($payload['nonce'])
        ) {
            throw ValidationException::withMessages([
                'qr_payload' => [
                    'Invalid event ticket QR payload.',
                ],
            ]);
        }

        return $payload;
    }

    /**
     * QR signing key.
     */
    private function qrSigningKey(): string
    {
        $key = config('app.key');

        if (!is_string($key) || $key === '') {
            throw new \RuntimeException(
                'Application key is not configured.'
            );
        }

        return $key;
    }
}