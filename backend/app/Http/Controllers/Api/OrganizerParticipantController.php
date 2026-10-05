<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventTicket;
use App\Services\CheckInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrganizerParticipantController extends Controller
{
    /**
     * Authorize authenticated organizer
     * untuk event tertentu.
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

        /*
         * Security hardening:
         * Organizer harus sudah approved sebelum dapat
         * mengakses peserta/check-in event.
         */
        if ($organizer->status !== 'approved') {
            return response()->json([
                'message' => 'Organizer account is not approved.',
            ], 403);
        }

        /*
         * IDOR protection:
         * organizer hanya boleh mengakses event miliknya sendiri.
         *
         * events.organizer_id -> organizers.id
         */
        if (
            (int) $event->organizer_id !==
            (int) $organizer->id
        ) {
            return response()->json([
                'message' => 'You are not authorized to access this event.',
            ], 403);
        }

        return null;
    }

    /**
     * GET /organizer/events/{event}/participants
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

        /*
         * Limit pagination untuk mencegah request yang terlalu besar.
         */
        $perPage = min(
            max(
                $request->integer('per_page', 20),
                1
            ),
            100
        );

        $query = EventTicket::query()
            ->where(
                'event_id',
                $event->id
            )
            ->whereNotNull(
                'event_ticket_order_id'
            )
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
            $search = trim(
                $request->input('search')
            );

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where(
                        'ticket_code',
                        'ILIKE',
                        "%{$search}%"
                    )
                    ->orWhereHas(
                        'order',
                        function ($orderQuery) use ($search) {
                            $orderQuery
                                ->where(
                                    'order_code',
                                    'ILIKE',
                                    "%{$search}%"
                                )
                                ->orWhereHas(
                                    'user',
                                    function ($userQuery) use ($search) {
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
                                    }
                                );
                        }
                    );
                });
            }
        }

        $tickets = $query
            ->paginate($perPage)
            ->through(
                function (EventTicket $ticket) {
                    $order = $ticket->order;
                    $user = $order?->user;
                    $ticketType = $order?->ticketType;
                    $payment = $order?->payment;

                    return [
                        'ticket_code' =>
                            $ticket->ticket_code,

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
                            'order_code' =>
                                $order?->order_code,

                            'status' =>
                                $order?->status,

                            'quantity' =>
                                $order?->quantity,

                            'total_amount' =>
                                $order?->total_amount,

                            'confirmed_at' =>
                                $order?->confirmed_at,

                            'cancelled_at' =>
                                $order?->cancelled_at,
                        ],

                        'payment' => [
                            'status' =>
                                $payment?->status,

                            'method' =>
                                $payment?->method,

                            'amount' =>
                                $payment?->amount,
                        ],

                        'ticket_status' =>
                            $ticket->status,

                        'check_in' => [
                            'status' =>
                                $ticket->checked_in_at
                                    ? 'checked_in'
                                    : 'not_checked_in',

                            'checked_in_at' =>
                                $ticket->checked_in_at,
                        ],

                        'issued_at' =>
                            $ticket->issued_at,
                    ];
                }
            );

        return response()->json(
            $tickets
        );
    }

    /**
     * GET /organizer/events/{event}/participants/export
     */
    public function export(
        Request $request,
        Event $event
    ): StreamedResponse|JsonResponse {
        $authorizationError = $this->authorizeOrganizerEvent(
            $request,
            $event
        );

        if ($authorizationError) {
            return $authorizationError;
        }

        $query = EventTicket::query()
            ->where(
                'event_id',
                $event->id
            )
            ->whereNotNull(
                'event_ticket_order_id'
            )
            ->with([
                'order.user',
                'order.ticketType',
                'order.payment',
            ])
            ->orderBy('id');

        /*
         * Optional search.
         */
        if ($request->filled('search')) {
            $search = trim(
                $request->input('search')
            );

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where(
                        'ticket_code',
                        'ILIKE',
                        "%{$search}%"
                    )
                    ->orWhereHas(
                        'order',
                        function ($orderQuery) use ($search) {
                            $orderQuery
                                ->where(
                                    'order_code',
                                    'ILIKE',
                                    "%{$search}%"
                                )
                                ->orWhereHas(
                                    'user',
                                    function ($userQuery) use ($search) {
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
                                    }
                                );
                        }
                    );
                });
            }
        }

        $filename = sprintf(
            'participants-%s-%s.csv',
            $event->id,
            now()->format('Ymd-His')
        );

        return response()->streamDownload(
            function () use ($query) {
                $handle = fopen(
                    'php://output',
                    'w'
                );

                /*
                 * UTF-8 BOM untuk Excel Windows.
                 */
                fwrite(
                    $handle,
                    "\xEF\xBB\xBF"
                );

                fputcsv(
                    $handle,
                    [
                        'Ticket Code',
                        'Participant Name',
                        'Participant Email',
                        'Ticket Type',
                        'Order Code',
                        'Order Status',
                        'Payment Status',
                        'Payment Method',
                        'Amount',
                        'Ticket Status',
                        'Check-in Status',
                        'Checked-in At',
                        'Issued At',
                    ]
                );

                $query->chunkById(
                    500,
                    function ($tickets) use ($handle) {
                        foreach ($tickets as $ticket) {
                            $order = $ticket->order;
                            $user = $order?->user;
                            $ticketType = $order?->ticketType;
                            $payment = $order?->payment;

                            fputcsv(
                                $handle,
                                [
                                    $ticket->ticket_code,
                                    $user?->name,
                                    $user?->email,
                                    $ticketType?->name,
                                    $order?->order_code,
                                    $order?->status,
                                    $payment?->status,
                                    $payment?->method,
                                    $payment?->amount,
                                    $ticket->status,
                                    $ticket->checked_in_at
                                        ? 'checked_in'
                                        : 'not_checked_in',
                                    $ticket->checked_in_at,
                                    $ticket->issued_at,
                                ]
                            );
                        }
                    }
                );

                fclose($handle);
            },
            $filename,
            [
                'Content-Type' =>
                    'text/csv; charset=UTF-8',

                'Content-Disposition' =>
                    'attachment; filename="' .
                    $filename .
                    '"',
            ]
        );
    }

    /**
     * GET /organizer/events/{event}/participants/{ticket}
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
         * Ticket harus benar-benar milik event
         * yang sedang dibuka.
         */
        if (
            (int) $ticket->event_id !==
            (int) $event->id ||

            $ticket->event_ticket_order_id === null
        ) {
            return response()->json([
                'message' =>
                    'Participant ticket not found for this event.',
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
                'ticket_code' =>
                    $ticket->ticket_code,

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
                    'order_code' =>
                        $order?->order_code,

                    'status' =>
                        $order?->status,

                    'quantity' =>
                        $order?->quantity,

                    'total_amount' =>
                        $order?->total_amount,

                    'confirmed_at' =>
                        $order?->confirmed_at,

                    'cancelled_at' =>
                        $order?->cancelled_at,
                ],

                'payment' => [
                    'status' =>
                        $payment?->status,

                    'method' =>
                        $payment?->method,

                    'amount' =>
                        $payment?->amount,
                ],

                'ticket_status' =>
                    $ticket->status,

                'check_in' => [
                    'status' =>
                        $ticket->checked_in_at
                            ? 'checked_in'
                            : 'not_checked_in',

                    'checked_in_at' =>
                        $ticket->checked_in_at,
                ],

                'issued_at' =>
                    $ticket->issued_at,
            ],
        ]);
    }

    /**
     * POST /organizer/events/{event}/participants/{ticket}/check-in
     *
     * Check-in menggunakan signed QR payload.
     */
    public function checkIn(
        Request $request,
        Event $event,
        EventTicket $ticket,
        CheckInService $checkInService
    ): JsonResponse {
        /*
         * Authorize organizer -> event.
         */
        $authorizationError = $this->authorizeOrganizerEvent(
            $request,
            $event
        );

        if ($authorizationError) {
            return $authorizationError;
        }

        /*
         * Pastikan URL ticket memang milik event.
         */
        if (
            (int) $ticket->event_id !==
            (int) $event->id
        ) {
            return response()->json([
                'message' =>
                    'This ticket does not belong to this event.',
            ], 404);
        }

        /*
         * QR payload wajib.
         */
        $validated = $request->validate([
            'qr_payload' => [
                'required',
                'string',
                'max:5000',
            ],
        ]);

        try {
            /*
             * Process QR melalui CheckInService.
             */
            $result = $checkInService->checkIn(
                $validated['qr_payload'],
                $request->user()
            );

            /*
             * Endpoint ini khusus EventTicket.
             */
            if (!$result instanceof EventTicket) {
                return response()->json([
                    'message' =>
                        'Invalid ticket type for this event.',
                ], 422);
            }

            /*
             * QR result harus sama dengan
             * ticket ID dari URL.
             */
            if (
                (int) $result->id !==
                (int) $ticket->id
            ) {
                return response()->json([
                    'message' =>
                        'QR ticket does not match the requested participant.',
                ], 422);
            }

            /*
             * Pastikan event juga sama.
             */
            if (
                (int) $result->event_id !==
                (int) $event->id
            ) {
                return response()->json([
                    'message' =>
                        'This ticket does not belong to this event.',
                ], 422);
            }

            $result->load([
                'order.user',
                'order.ticketType',
                'order.payment',
            ]);

            return response()->json([
                'message' =>
                    'Check-in successful.',

                'data' => [
                    'ticket_code' =>
                        $result->ticket_code,

                    'participant' => [
                        'id' =>
                            $result->order?->user?->id,

                        'name' =>
                            $result->order?->user?->name,

                        'email' =>
                            $result->order?->user?->email,
                    ],

                    'ticket_type' => [
                        'id' =>
                            $result->order?->ticketType?->id,

                        'name' =>
                            $result->order?->ticketType?->name,
                    ],

                    'order' => [
                        'order_code' =>
                            $result->order?->order_code,

                        'status' =>
                            $result->order?->status,
                    ],

                    'payment' => [
                        'status' =>
                            $result->order?->payment?->status,

                        'method' =>
                            $result->order?->payment?->method,

                        'amount' =>
                            $result->order?->payment?->amount,
                    ],

                    'ticket_status' =>
                        $result->status,

                    'check_in' => [
                        'status' =>
                            'checked_in',

                        'checked_in_at' =>
                            $result->checked_in_at,
                    ],
                ],
            ], 200);

        } catch (ValidationException $e) {
            throw $e;
        }
    }
}