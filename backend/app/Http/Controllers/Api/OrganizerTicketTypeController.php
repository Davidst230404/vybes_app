<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventTicketType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrganizerTicketTypeController extends Controller
{
    /**
     * List ticket types belonging to organizer's event.
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

        return response()->json([
            'data' => $event
                ->ticketTypes()
                ->latest()
                ->get(),
        ]);
    }

    /**
     * Create a new event ticket type.
     */
    public function store(
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
         * Ticket types must not be created for terminal events.
         */
        if (
            in_array(
                $event->status,
                [
                    'cancelled',
                    'completed',
                ],
                true
            )
        ) {
            return response()->json([
                'message' =>
                    'Ticket types cannot be created for this event status.',
            ], 422);
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'price' => [
                'required',
                'numeric',
                'min:0',
            ],

            'quota' => [
                'required',
                'integer',
                'min:1',
            ],

            'sales_starts_at' => [
                'nullable',
                'date',
            ],

            'sales_ends_at' => [
                'nullable',
                'date',
                'after:sales_starts_at',
            ],

            'status' => [
                'nullable',
                'in:active,inactive',
            ],
        ]);

        /*
         * Calculate/create server-side inventory state.
         *
         * Clients are never allowed to set sold/reserved.
         */
        $ticketType = EventTicketType::create([
            'event_id' => $event->id,

            'name' =>
                $validated['name'],

            'description' =>
                $validated['description'] ?? null,

            'price' =>
                $validated['price'],

            'quota' =>
                $validated['quota'],

            'sold' => 0,

            'reserved' => 0,

            'sales_starts_at' =>
                $validated['sales_starts_at'] ?? null,

            'sales_ends_at' =>
                $validated['sales_ends_at'] ?? null,

            'status' =>
                $validated['status'] ?? 'active',
        ]);

        return response()->json([
            'message' =>
                'Ticket type created successfully.',

            'data' =>
                $ticketType,
        ], 201);
    }

    /**
     * Update an existing ticket type.
     */
    public function update(
        Request $request,
        Event $event,
        EventTicketType $ticketType
    ): JsonResponse {
        $authorizationError = $this->authorizeOrganizerEvent(
            $request,
            $event
        );

        if ($authorizationError) {
            return $authorizationError;
        }

        /*
         * IDOR protection:
         * the ticket type must belong to the event from the URL.
         */
        if (
            (int) $ticketType->event_id !==
            (int) $event->id
        ) {
            return response()->json([
                'message' =>
                    'Ticket type does not belong to this event.',
            ], 404);
        }

        /*
         * Do not modify inventory configuration of
         * cancelled/completed events.
         */
        if (
            in_array(
                $event->status,
                [
                    'cancelled',
                    'completed',
                ],
                true
            )
        ) {
            return response()->json([
                'message' =>
                    'Ticket types cannot be modified for this event status.',
            ], 422);
        }

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
            ],

            'description' => [
                'sometimes',
                'nullable',
                'string',
            ],

            'price' => [
                'sometimes',
                'numeric',
                'min:0',
            ],

            'quota' => [
                'sometimes',
                'integer',
                'min:1',
            ],

            'sales_starts_at' => [
                'sometimes',
                'nullable',
                'date',
            ],

            'sales_ends_at' => [
                'sometimes',
                'nullable',
                'date',
                'after:sales_starts_at',
            ],

            'status' => [
                'sometimes',
                'in:active,inactive',
            ],
        ]);

        /*
         * Lock the ticket type before validating quota.
         *
         * This prevents two concurrent updates from reading
         * stale sold/reserved values.
         */
        $lockedTicketType = DB::transaction(
            function () use (
                $ticketType,
                $validated
            ) {
                $lockedTicketType = EventTicketType::query()
                    ->whereKey($ticketType->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $newQuota = array_key_exists(
                    'quota',
                    $validated
                )
                    ? (int) $validated['quota']
                    : (int) $lockedTicketType->quota;

                $sold = (int) $lockedTicketType->sold;

                $reserved = (int) $lockedTicketType->reserved;

                /*
                 * Inventory invariant:
                 *
                 * quota >= sold + reserved
                 *
                 * Never let an organizer reduce quota below
                 * inventory that is already committed.
                 */
                if (
                    $newQuota <
                    ($sold + $reserved)
                ) {
                    throw ValidationException::withMessages([
                        'quota' => [
                            'Quota cannot be lower than tickets already sold or reserved.',
                        ],
                    ]);
                }

                /*
                 * Prevent sales window with invalid ordering
                 * when only one field is updated.
                 */
                $newSalesStartsAt =
                    array_key_exists(
                        'sales_starts_at',
                        $validated
                    )
                        ? $validated['sales_starts_at']
                        : $lockedTicketType->sales_starts_at;

                $newSalesEndsAt =
                    array_key_exists(
                        'sales_ends_at',
                        $validated
                    )
                        ? $validated['sales_ends_at']
                        : $lockedTicketType->sales_ends_at;

                if (
                    $newSalesStartsAt !== null &&
                    $newSalesEndsAt !== null &&
                    strtotime((string) $newSalesEndsAt) <=
                    strtotime((string) $newSalesStartsAt)
                ) {
                    throw ValidationException::withMessages([
                        'sales_ends_at' => [
                            'The sales end time must be after the sales start time.',
                        ],
                    ]);
                }

                /*
                 * Never allow client input to modify
                 * authoritative inventory counters.
                 */
                unset(
                    $validated['sold'],
                    $validated['reserved'],
                    $validated['event_id']
                );

                $lockedTicketType->update(
                    $validated
                );

                return $lockedTicketType->fresh();
            }
        );

        return response()->json([
            'message' =>
                'Ticket type updated successfully.',

            'data' =>
                $lockedTicketType,
        ]);
    }

    /**
     * Delete a ticket type.
     */
    public function destroy(
        Request $request,
        Event $event,
        EventTicketType $ticketType
    ): JsonResponse {
        $authorizationError = $this->authorizeOrganizerEvent(
            $request,
            $event
        );

        if ($authorizationError) {
            return $authorizationError;
        }

        /*
         * IDOR protection.
         */
        if (
            (int) $ticketType->event_id !==
            (int) $event->id
        ) {
            return response()->json([
                'message' =>
                    'Ticket type does not belong to this event.',
            ], 404);
        }

        /*
         * Never delete ticket types from terminal events.
         */
        if (
            in_array(
                $event->status,
                [
                    'cancelled',
                    'completed',
                ],
                true
            )
        ) {
            return response()->json([
                'message' =>
                    'Ticket types cannot be deleted for this event status.',
            ], 422);
        }

        /*
         * Lock before checking inventory so the decision is
         * based on current authoritative values.
         */
        $canDelete = DB::transaction(
            function () use ($ticketType) {
                $lockedTicketType = EventTicketType::query()
                    ->whereKey($ticketType->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $sold = (int) $lockedTicketType->sold;

                $reserved = (int) $lockedTicketType->reserved;

                if (
                    $sold > 0 ||
                    $reserved > 0
                ) {
                    return false;
                }

                $lockedTicketType->delete();

                return true;
            }
        );

        if (!$canDelete) {
            return response()->json([
                'message' =>
                    'Ticket type cannot be deleted because tickets have already been sold or reserved.',
            ], 422);
        }

        return response()->json([
            'message' =>
                'Ticket type deleted successfully.',
        ]);
    }

    /**
     * Authorize organizer ownership and approval status.
     */
    private function authorizeOrganizerEvent(
        Request $request,
        Event $event
    ): ?JsonResponse {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' =>
                    'Unauthenticated.',
            ], 401);
        }

        /*
         * User must have organizer role.
         */
        if (!$user->hasRole('organizer')) {
            return response()->json([
                'message' =>
                    'Only organizers can manage ticket types.',
            ], 403);
        }

        /*
         * Organizer profile must exist.
         */
        $organizer = $user->organizer;

        if (!$organizer) {
            return response()->json([
                'message' =>
                    'Organizer profile not found.',
            ], 403);
        }

        /*
         * Security hardening:
         * role alone is not sufficient.
         */
        if ($organizer->status !== 'approved') {
            return response()->json([
                'message' =>
                    'Organizer account is not approved.',
            ], 403);
        }

        /*
         * IDOR protection:
         *
         * events.organizer_id -> organizers.id
         */
        if (
            (int) $event->organizer_id !==
            (int) $organizer->id
        ) {
            return response()->json([
                'message' =>
                    'You are not authorized to manage this event.',
            ], 403);
        }

        return null;
    }
}