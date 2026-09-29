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
             * Idempotency:
             * Jangan membuat tiket baru kalau tiket untuk order
             * tersebut sudah pernah dibuat.
             */
            $existingTickets = EventTicket::query()
                ->where('event_ticket_order_id', $order->id)
                ->get();

            if ($existingTickets->count() >= $order->quantity) {
                return $existingTickets->all();
            }

            $tickets = [];

            $remaining = $order->quantity - $existingTickets->count();

            for ($i = 0; $i < $remaining; $i++) {
                $tickets[] = EventTicket::create([
                    'event_ticket_order_id' => $order->id,
                    'event_id' => $order->event_id,
                    'ticket_code' => $this->generateTicketCode(),
                    'status' => 'active',
                    'qr_payload' => $this->generateQrPayload($order),
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
            EventTicket::where('ticket_code', $code)->exists()
        );

        return $code;
    }

    /**
     * Generate QR payload untuk event ticket.
     */
    private function generateQrPayload(EventTicketOrder $order): string
    {
        return json_encode([
            'type' => 'VYBES_EVENT_TICKET',
            'order_id' => $order->id,
            'order_code' => $order->order_code,
            'event_id' => $order->event_id,
        ], JSON_THROW_ON_ERROR);
    }
}