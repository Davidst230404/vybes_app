<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventTicket;
use App\Services\CheckInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrganizerParticipantController extends Controller
{
    /**
     * Check whether the authenticated user
     * is authorized to access the event.
     */
    private function authorizeOrganizerEvent(
        Request $request,
        Event $event
    ): ?JsonResponse {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!$user->hasRole('organizer')) {
            return response()->json([
                'message' => 'Only organizers can access participants.',
            ], 403);
        }

        $organizer = $user->organizer;

        if (!$organizer) {
            return response()->json([
                'message' => 'Organizer profile not found.',
            ], 403);
        }

        if ($event->organizer_id !== $organizer->id) {
            return response()->json([
                'message' => 'You are not authorized to access this event.',
            ], 403);
        }

        return null;
    }

    /**
     * List participants for an organizer's event.
     */
    public function index(
        Request $request,
        Event $event
    ): JsonResponse {
        $authorizationError = $this->authorizeOrganizerEvent(
            $request,
            $event
        );

        if ($authorizationError) {
            return $authorizationError;
        }

        $perPage = min(
            max($request->integer('per_page', 20), 1),
            100
        );

        $query = EventTicket::query()
            ->where('event_id', $event->id)
            ->whereNotNull('event_ticket_order_id')
            ->with([
                'order.user',
                'order.ticketType',
                'order.payment',
            ])
            ->orderByDesc('id');

        /*
         * Search:
         * - ticket code
         * - order code
         * - participant name
         * - participant email
         */
        if ($request->filled('search')) {
            $search = trim($request->input('search'));

            if ($search !== '') {
                $query->where(function ($q) use ($search) {

                    $q->where(
                        'ticket_code',
                        'ILIKE',
                        "%{$search}%"
                    )

                    ->orWhereHas('order', function ($orderQuery) use ($search) {

                        $orderQuery->where(
                            'order_code',
                            'ILIKE',
                            "%{$search}%"
                        )

                        ->orWhereHas('user', function ($userQuery) use ($search) {

                            $userQuery
                                ->where(
                                    'name',
                                    'ILIKE',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'email',
                                    'ILIKE',
                                    "%{$search}%"
                                );
                        });
                    });
                });
            }
        }

        $tickets = $query
            ->paginate($perPage)
            ->through(function (EventTicket $ticket) {

                $order = $ticket->order;
                $user = $order?->user;
                $ticketType = $order?->ticketType;
                $payment = $order?->payment;

                return [
                    'ticket_code' => $ticket->ticket_code,

                    'participant' => [
                        'id' => $user?->id,
                        'name' => $user?->name,
                        'email' => $user?->email,
                    ],

                    'ticket_type' => [
                        'id' => $ticketType?->id,
                        'name' => $ticketType?->name,
                    ],

                    'order' => [
                        'order_code' => $order?->order_code,
                        'status' => $order?->status,
                        'quantity' => $order?->quantity,
                        'total_amount' => $order?->total_amount,
                        'confirmed_at' => $order?->confirmed_at,
                        'cancelled_at' => $order?->cancelled_at,
                    ],

                    'payment' => [
                        'status' => $payment?->status,
                        'method' => $payment?->method,
                        'amount' => $payment?->amount,
                    ],

                    'ticket_status' => $ticket->status,

                    'check_in' => [
                        'status' => $ticket->checked_in_at
                            ? 'checked_in'
                            : 'not_checked_in',

                        'checked_in_at' => $ticket->checked_in_at,
                    ],

                    'issued_at' => $ticket->issued_at,
                ];
            });

        return response()->json($tickets);
    }

    /**
     * Show participant detail.
     */
    public function show(
        Request $request,
        Event $event,
        EventTicket $ticket
    ): JsonResponse {
        $authorizationError = $this->authorizeOrganizerEvent(
            $request,
            $event
        );

        if ($authorizationError) {
            return $authorizationError;
        }

        /*
         * Make sure this ticket belongs
         * to the requested event.
         */
        if (
            $ticket->event_id !== $event->id ||
            $ticket->event_ticket_order_id === null
        ) {
            return response()->json([
                'message' => 'Participant ticket not found for this event.',
            ], 404);
        }

        $ticket->load([
            'order.user',
            'order.ticketType',
            'order.payment',
        ]);

        $order = $ticket->order;
        $user = $order?->user;
        $ticketType = $order?->ticketType;
        $payment = $order?->payment;

        return response()->json([
            'data' => [
                'ticket_code' => $ticket->ticket_code,

                'participant' => [
                    'id' => $user?->id,
                    'name' => $user?->name,
                    'email' => $user?->email,
                ],

                'ticket_type' => [
                    'id' => $ticketType?->id,
                    'name' => $ticketType?->name,
                ],

                'order' => [
                    'order_code' => $order?->order_code,
                    'status' => $order?->status,
                    'quantity' => $order?->quantity,
                    'total_amount' => $order?->total_amount,
                    'confirmed_at' => $order?->confirmed_at,
                    'cancelled_at' => $order?->cancelled_at,
                ],

                'payment' => [
                    'status' => $payment?->status,
                    'method' => $payment?->method,
                    'amount' => $payment?->amount,
                ],

                'ticket_status' => $ticket->status,

                'check_in' => [
                    'status' => $ticket->checked_in_at
                        ? 'checked_in'
                        : 'not_checked_in',

                    'checked_in_at' => $ticket->checked_in_at,
                ],

                'issued_at' => $ticket->issued_at,
            ],
        ]);
    }

    /**
     * Check in an event ticket using its signed QR payload.
     */
    public function checkIn(
        Request $request,
        Event $event,
        CheckInService $checkInService
    ): JsonResponse {
        $authorizationError = $this->authorizeOrganizerEvent(
            $request,
            $event
        );

        if ($authorizationError) {
            return $authorizationError;
        }

        $validated = $request->validate([
            'qr_payload' => [
                'required',
                'string',
                'max:5000',
            ],
        ]);

        try {
            $result = $checkInService->checkIn(
                $validated['qr_payload'],
                $request->user()
            );

            /*
             * Organizer event check-in must only accept
             * tickets belonging to the event in the URL.
             */
            if ($result instanceof EventTicket) {

                if ($result->event_id !== $event->id) {
                    return response()->json([
                        'message' => 'This ticket does not belong to this event.',
                    ], 422);
                }

                $result->load([
                    'order.user',
                    'order.ticketType',
                    'order.payment',
                ]);

                return response()->json([
                    'message' => 'Check-in successful.',
                    'data' => [
                        'ticket_code' => $result->ticket_code,

                        'participant' => [
                            'id' => $result->order?->user?->id,
                            'name' => $result->order?->user?->name,
                            'email' => $result->order?->user?->email,
                        ],

                        'ticket_type' => [
                            'id' => $result->order?->ticketType?->id,
                            'name' => $result->order?->ticketType?->name,
                        ],

                        'order' => [
                            'order_code' => $result->order?->order_code,
                            'status' => $result->order?->status,
                        ],

                        'payment' => [
                            'status' => $result->order?->payment?->status,
                            'method' => $result->order?->payment?->method,
                            'amount' => $result->order?->payment?->amount,
                        ],

                        'ticket_status' => $result->status,

                        'check_in' => [
                            'status' => 'checked_in',
                            'checked_in_at' => $result->checked_in_at,
                        ],
                    ],
                ], 200);
            }

            /*
             * This endpoint is specifically for event tickets.
             */
            return response()->json([
                'message' => 'Invalid ticket type for this event.',
            ], 422);

        } catch (ValidationException $e) {
            throw $e;
        }
    }
}