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
         * Event QR:
         *
         * v1.{base64url(payload)}.{signature}
         *
         * Booking QR lama:
         * JSON payload.
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
     * Hanya Merchant yang memiliki venue tersebut
     * yang dapat melakukan check-in.
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
             * Decode booking QR.
             */
            $payload = json_decode(
                $qrPayload,
                true
            );

            if (!is_array($payload)) {
                throw ValidationException::withMessages([
                    'qr_payload' => [
                        'Invalid QR payload.',
                    ],
                ]);
            }

            /*
             * Validate payload.
             */
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
             * Booking ticket hanya boleh
             * di-check-in oleh Merchant.
             */
            if (!$user->hasRole('merchant')) {
                throw ValidationException::withMessages([
                    'user' => [
                        'Only merchants can check in booking tickets.',
                    ],
                ]);
            }

            /*
             * Lock ticket untuk mencegah
             * concurrent double check-in.
             */
            $ticket = BookingTicket::query()
                ->with([
                    'booking.venue',
                    'booking.items.resource',
                    'booking.payment',
                ])
                ->where(
                    'booking_id',
                    $payload['booking_id']
                )
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
             * Validate Merchant profile.
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
             * Security hardening:
             * hanya merchant yang sudah approved
             * yang boleh melakukan check-in.
             */
            if ($merchant->status !== 'approved') {
                throw ValidationException::withMessages([
                    'merchant' => [
                        'Merchant account is not approved.',
                    ],
                ]);
            }

            /*
             * Validate Merchant ownership.
             */
            if (
                !$ticket->booking->venue ||
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
             * Validate booking code.
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
             * Validate ticket status.
             */
            if ($ticket->status !== 'active') {
                throw ValidationException::withMessages([
                    'ticket' => [
                        'This ticket is not active.',
                    ],
                ]);
            }

            /*
             * Validate booking status.
             */
            if ($ticket->booking->status !== 'confirmed') {
                throw ValidationException::withMessages([
                    'booking' => [
                        'This booking is not confirmed.',
                    ],
                ]);
            }

            /*
             * Validate payment.
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
             * Validate booking date.
             */
            $bookingDate = $ticket->booking
                ->starts_at
                ->toDateString();

            $today = now()->toDateString();

            if ($bookingDate !== $today) {
                throw ValidationException::withMessages([
                    'booking' => [
                        'This ticket can only be checked in on the booking date.',
                    ],
                ]);
            }

            /*
             * Prevent duplicate check-in.
             */
            if ($ticket->checked_in_at !== null) {
                throw ValidationException::withMessages([
                    'ticket' => [
                        'This ticket has already been checked in.',
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
                'booking.venue',
                'booking.items.resource',
                'booking.payment',
            ]);
        });
    }

    /**
     * Check-in event ticket.
     *
     * Hanya Organizer dari event tersebut
     * yang boleh melakukan check-in.
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
             * Decode dan verify signed QR.
             */
            $payload = $this->decodeAndVerifyEventQr(
                $qrPayload
            );

            /*
             * Hanya Organizer.
             */
            if (!$user->hasRole('organizer')) {
                throw ValidationException::withMessages([
                    'user' => [
                        'Only organizers can check in event tickets.',
                    ],
                ]);
            }

            /*
             * Cari ticket berdasarkan ticket_code
             * dan lock row.
             */
            $ticket = EventTicket::query()
                ->with([
                    'order.payment',
                    'event',
                ])
                ->where(
                    'ticket_code',
                    $payload['ticket_code']
                )
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
             * QR -> Ticket validation.
             */
            if (
                (int) $ticket->event_ticket_order_id !==
                (int) $payload['order_id']
            ) {
                throw ValidationException::withMessages([
                    'qr_payload' => [
                        'QR code does not match the ticket.',
                    ],
                ]);
            }

            /*
             * QR -> Event validation.
             */
            if (
                (int) $ticket->event_id !==
                (int) $payload['event_id']
            ) {
                throw ValidationException::withMessages([
                    'qr_payload' => [
                        'QR code does not match the event.',
                    ],
                ]);
            }

            /*
             * Validate event.
             */
            if (!$ticket->event) {
                throw ValidationException::withMessages([
                    'event' => [
                        'Event not found.',
                    ],
                ]);
            }

            /*
             * Validate Organizer ownership.
             *
             * events.organizer_id ->
             * organizers.id
             *
             * users.id ->
             * organizers.user_id
             */
            $organizer = $user->organizer;

            /*
             * Security hardening:
             * organizer harus ada, approved,
             * dan memiliki event tersebut.
             */
            if (
                !$organizer ||
                $organizer->status !== 'approved' ||
                (int) $ticket->event->organizer_id !==
                (int) $organizer->id
            ) {
                throw ValidationException::withMessages([
                    'event' => [
                        'You are not authorized to check in tickets for this event.',
                    ],
                ]);
            }

            /*
             * Validate ticket status.
             */
            if ($ticket->status !== 'active') {
                throw ValidationException::withMessages([
                    'ticket' => [
                        'This event ticket is not active.',
                    ],
                ]);
            }

            /*
             * Validate order.
             */
            if (!$ticket->order) {
                throw ValidationException::withMessages([
                    'order' => [
                        'Event ticket order not found.',
                    ],
                ]);
            }

            /*
             * Validate order code dari QR.
             */
            if (
                $ticket->order->order_code !==
                $payload['order_code']
            ) {
                throw ValidationException::withMessages([
                    'qr_payload' => [
                        'QR code does not match the ticket order.',
                    ],
                ]);
            }

            /*
             * Validate order status.
             */
            if ($ticket->order->status !== 'confirmed') {
                throw ValidationException::withMessages([
                    'order' => [
                        'This event ticket order is not confirmed.',
                    ],
                ]);
            }

            /*
             * Validate payment.
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
             * Validate event start date.
             */
            if (!$ticket->event->starts_at) {
                throw ValidationException::withMessages([
                    'event' => [
                        'Event start date is not configured.',
                    ],
                ]);
            }

            $eventDate = $ticket->event
                ->starts_at
                ->toDateString();

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
             *
             * lockForUpdate() di atas memastikan
             * dua request concurrent tidak dapat
             * sama-sama melewati check ini.
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
     *
     * Format:
     *
     * v1.{base64url(payload)}.{signature}
     */
    private function decodeAndVerifyEventQr(
        string $qrPayload
    ): array {
        $parts = explode(
            '.',
            $qrPayload
        );

        if (count($parts) !== 3) {
            throw ValidationException::withMessages([
                'qr_payload' => [
                    'Invalid signed event ticket QR code.',
                ],
            ]);
        }

        [
            $version,
            $encodedPayload,
            $signature
        ] = $parts;

        /*
         * Validate version.
         */
        if ($version !== 'v1') {
            throw ValidationException::withMessages([
                'qr_payload' => [
                    'Unsupported QR version.',
                ],
            ]);
        }

        /*
         * Payload + signature wajib.
         */
        if (
            $encodedPayload === '' ||
            $signature === ''
        ) {
            throw ValidationException::withMessages([
                'qr_payload' => [
                    'Invalid signed event ticket QR code.',
                ],
            ]);
        }

        /*
         * Signature dihitung dari EXACT
         * Base64URL string yang ada di QR.
         */
        $expectedSignature = hash_hmac(
            'sha256',
            $encodedPayload,
            $this->qrSigningKey()
        );

        /*
         * Timing-safe comparison.
         */
        if (!hash_equals(
            $expectedSignature,
            $signature
        )) {
            throw ValidationException::withMessages([
                'qr_payload' => [
                    'Invalid QR signature.',
                ],
            ]);
        }

        /*
         * Convert Base64URL -> Base64.
         */
        $base64 = strtr(
            $encodedPayload,
            '-_',
            '+/'
        );

        /*
         * =====================================================
         * IMPORTANT:
         * Padding HARUS dilakukan SEBELUM base64_decode().
         * =====================================================
         */
        $padding = strlen($base64) % 4;

        if ($padding !== 0) {
            $base64 .= str_repeat(
                '=',
                4 - $padding
            );
        }

        /*
         * Decode Base64.
         */
        $json = base64_decode(
            $base64,
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
         * Decode JSON.
         */
        $payload = json_decode(
            $json,
            true
        );

        if (
            !is_array($payload) ||
            json_last_error() !== JSON_ERROR_NONE
        ) {
            throw ValidationException::withMessages([
                'qr_payload' => [
                    'Invalid QR payload.',
                ],
            ]);
        }

        /*
         * Validate payload structure.
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

        if (
            !is_string($key) ||
            $key === ''
        ) {
            throw new \RuntimeException(
                'Application key is not configured.'
            );
        }

        return $key;
    }
}