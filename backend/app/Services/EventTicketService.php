<?php

namespace App\Services;

use App\Models\EventTicket;
use App\Models\EventTicketOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventTicketService
{
    /**
     * Generate individual tickets berdasarkan quantity dari order.
     *
     * Setiap ticket memiliki:
     * - ticket_code unik
     * - QR payload unik
     * - signature HMAC untuk mencegah manipulasi payload
     *
     * Idempotent:
     * - Tidak membuat duplicate ticket.
     * - Ticket lama tetap digunakan.
     * - Legacy QR akan di-upgrade menjadi signed QR v1.
     */
    public function generateTickets(EventTicketOrder $order): array
    {
        return DB::transaction(function () use ($order) {

            $order = EventTicketOrder::query()
                ->with(['event'])
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status !== 'confirmed') {
                throw ValidationException::withMessages([
                    'order' => [
                        'Event ticket order must be confirmed before generating tickets.',
                    ],
                ]);
            }

            /*
             * Ambil semua ticket yang sudah pernah dibuat
             * untuk order ini.
             */
            $existingTickets = EventTicket::query()
                ->where('event_ticket_order_id', $order->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            /*
             * =========================================================
             * UPGRADE LEGACY QR
             * =========================================================
             *
             * Ticket lama mungkin masih memiliki QR seperti:
             *
             * {
             *     "type": "VYBES_EVENT_TICKET",
             *     "order_id": 7,
             *     "order_code": "...",
             *     "event_id": 2
             * }
             *
             * QR tersebut tidak memiliki signature.
             *
             * Kalau QR belum menggunakan format:
             *
             * v1.{base64url}.{signature}
             *
             * maka generate ulang berdasarkan ticket yang sama.
             *
             * Ticket code TIDAK berubah.
             * ID ticket TIDAK berubah.
             */
            foreach ($existingTickets as $ticket) {

                if (!$this->isSignedQr($ticket->qr_payload)) {
                    $ticket->update([
                        'qr_payload' => $this->generateQrPayload(
                            order: $order,
                            ticketCode: $ticket->ticket_code
                        ),
                    ]);

                    /*
                     * Refresh object supaya qr_payload terbaru
                     * tersedia pada return value.
                     */
                    $ticket->refresh();
                }
            }

            /*
             * =========================================================
             * IDEMPOTENCY
             * =========================================================
             *
             * Kalau jumlah ticket sudah mencukupi quantity order,
             * jangan membuat ticket baru.
             *
             * Ticket lama yang legacy sudah di-upgrade di atas.
             */
            if ($existingTickets->count() >= $order->quantity) {
                return $existingTickets->all();
            }

            /*
             * =========================================================
             * CREATE MISSING TICKETS
             * =========================================================
             */

            $tickets = $existingTickets->all();

            $remaining = $order->quantity - $existingTickets->count();

            for ($i = 0; $i < $remaining; $i++) {

                /*
                 * Ticket code dibuat terlebih dahulu karena akan
                 * menjadi identifier unik di dalam QR.
                 */
                $ticketCode = $this->generateTicketCode();

                /*
                 * QR dibuat unik untuk setiap ticket.
                 */
                $qrPayload = $this->generateQrPayload(
                    order: $order,
                    ticketCode: $ticketCode
                );

                $tickets[] = EventTicket::create([
                    'event_ticket_order_id' => $order->id,
                    'event_id' => $order->event_id,
                    'ticket_code' => $ticketCode,
                    'status' => 'active',
                    'qr_payload' => $qrPayload,
                    'issued_at' => now(),
                ]);
            }

            return $tickets;
        });
    }

    /**
     * Generate unique ticket code.
     */
    private function generateTicketCode(): string
    {
        do {
            $code = 'VYB-TKT-' . strtoupper(
                substr(bin2hex(random_bytes(5)), 0, 10)
            );
        } while (
            EventTicket::query()
                ->where('ticket_code', $code)
                ->exists()
        );

        return $code;
    }

    /**
     * Generate signed QR payload untuk event ticket.
     *
     * Format:
     *
     * v1.{base64url(payload)}.{signature}
     *
     * Payload setiap ticket berbeda karena memiliki:
     * - ticket_code
     * - nonce
     *
     * Signature digunakan untuk mendeteksi manipulasi QR.
     */
    private function generateQrPayload(
        EventTicketOrder $order,
        string $ticketCode
    ): string {
        $payload = [
            'type' => 'VYBES_EVENT_TICKET',
            'version' => 1,
            'ticket_code' => $ticketCode,
            'order_id' => $order->id,
            'order_code' => $order->order_code,
            'event_id' => $order->event_id,
            'nonce' => bin2hex(random_bytes(16)),
        ];

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        $encodedPayload = $this->base64UrlEncode($json);

        $signature = hash_hmac(
            'sha256',
            $encodedPayload,
            $this->qrSigningKey()
        );

        return "v1.{$encodedPayload}.{$signature}";
    }

    /**
     * Check apakah QR sudah menggunakan format signed v1.
     */
    private function isSignedQr(?string $qrPayload): bool
    {
        if (!$qrPayload) {
            return false;
        }

        /*
         * Format minimum:
         *
         * v1.payload.signature
         */
        $parts = explode('.', $qrPayload);

        return count($parts) === 3
            && $parts[0] === 'v1'
            && $parts[1] !== ''
            && $parts[2] !== '';
    }

    /**
     * Secret key khusus untuk signing QR.
     *
     * Untuk sementara menggunakan APP_KEY sehingga tidak perlu
     * migration/config tambahan.
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

    /**
     * Base64 URL-safe tanpa padding.
     */
    private function base64UrlEncode(string $value): string
    {
        return rtrim(
            strtr(
                base64_encode($value),
                '+/',
                '-_'
            ),
            '='
        );
    }
}