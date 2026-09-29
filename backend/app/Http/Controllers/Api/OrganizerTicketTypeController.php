<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventTicketType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizerTicketTypeController extends Controller
{
    public function index(Request $request, Event $event): JsonResponse
    {
        $organizer = $request->user()->organizer;

        if (!$organizer || $event->organizer_id !== $organizer->id) {
            return response()->json([
                'message' => 'You are not authorized to access this event.',
            ], 403);
        }

        return response()->json([
            'data' => $event->ticketTypes()->latest()->get(),
        ]);
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        $organizer = $request->user()->organizer;

        if (!$organizer || $event->organizer_id !== $organizer->id) {
            return response()->json([
                'message' => 'You are not authorized to manage this event.',
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'quota' => ['required', 'integer', 'min:1'],
            'sales_starts_at' => ['nullable', 'date'],
            'sales_ends_at' => ['nullable', 'date', 'after:sales_starts_at'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $ticketType = EventTicketType::create([
            'event_id' => $event->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'price' => $validated['price'],
            'quota' => $validated['quota'],
            'sold' => 0,
            'sales_starts_at' => $validated['sales_starts_at'] ?? null,
            'sales_ends_at' => $validated['sales_ends_at'] ?? null,
            'status' => $validated['status'] ?? 'active',
        ]);

        return response()->json([
            'message' => 'Ticket type created successfully.',
            'data' => $ticketType,
        ], 201);
    }

    public function update(
        Request $request,
        Event $event,
        EventTicketType $ticketType
    ): JsonResponse {
        $organizer = $request->user()->organizer;

        if (!$organizer || $event->organizer_id !== $organizer->id) {
            return response()->json([
                'message' => 'You are not authorized to manage this event.',
            ], 403);
        }

        if ($ticketType->event_id !== $event->id) {
            return response()->json([
                'message' => 'Ticket type does not belong to this event.',
            ], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'quota' => ['sometimes', 'integer', 'min:1'],
            'sales_starts_at' => ['nullable', 'date'],
            'sales_ends_at' => ['nullable', 'date', 'after:sales_starts_at'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        if (
            isset($validated['quota']) &&
            $validated['quota'] < $ticketType->sold
        ) {
            return response()->json([
                'message' => 'Quota cannot be lower than tickets already sold.',
            ], 422);
        }

        $ticketType->update($validated);

        return response()->json([
            'message' => 'Ticket type updated successfully.',
            'data' => $ticketType->fresh(),
        ]);
    }

    public function destroy(
        Request $request,
        Event $event,
        EventTicketType $ticketType
    ): JsonResponse {
        $organizer = $request->user()->organizer;

        if (!$organizer || $event->organizer_id !== $organizer->id) {
            return response()->json([
                'message' => 'You are not authorized to manage this event.',
            ], 403);
        }

        if ($ticketType->event_id !== $event->id) {
            return response()->json([
                'message' => 'Ticket type does not belong to this event.',
            ], 404);
        }

        if ($ticketType->sold > 0) {
            return response()->json([
                'message' => 'Ticket type cannot be deleted because tickets have already been sold.',
            ], 422);
        }

        $ticketType->delete();

        return response()->json([
            'message' => 'Ticket type deleted successfully.',
        ]);
    }
}