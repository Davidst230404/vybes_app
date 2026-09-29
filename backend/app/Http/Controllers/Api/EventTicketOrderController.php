<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventTicketOrder;
use App\Models\EventTicketType;
use App\Services\EventTicketPurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventTicketOrderController extends Controller
{
    /**
     * Create a temporary ticket order.
     */
    public function store(
        Request $request,
        Event $event,
        EventTicketPurchaseService $purchaseService
    ): JsonResponse {
        $validated = $request->validate([
            'ticket_type_id' => ['required', 'integer', 'exists:event_ticket_types,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10'],
        ]);

        $ticketType = EventTicketType::query()
            ->whereKey($validated['ticket_type_id'])
            ->where('event_id', $event->id)
            ->first();

        if (!$ticketType) {
            return response()->json([
                'message' => 'Ticket type does not belong to this event.',
            ], 404);
        }

        $order = $purchaseService->createHold(
            $request->user(),
            $ticketType,
            $validated['quantity']
        );

        return response()->json([
            'message' => 'Ticket order created successfully.',
            'data' => [
                'order' => $order,
            ],
        ], 201);
    }

    /**
     * Show customer's ticket order.
     */
    public function show(
        Request $request,
        EventTicketOrder $order
    ): JsonResponse {
        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'You are not authorized to access this order.',
            ], 403);
        }

        return response()->json([
            'data' => [
                'order' => $order->load([
                    'event',
                    'ticketType',
                ]),
            ],
        ]);
    }

    /**
     * Cancel a held ticket order.
     */
    public function cancel(
        Request $request,
        EventTicketOrder $order,
        EventTicketPurchaseService $purchaseService
    ): JsonResponse {
        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'You are not authorized to cancel this order.',
            ], 403);
        }

        $order = $purchaseService->cancelOrder($order);

        return response()->json([
            'message' => 'Ticket order cancelled successfully.',
            'data' => [
                'order' => $order,
            ],
        ]);
    }
}