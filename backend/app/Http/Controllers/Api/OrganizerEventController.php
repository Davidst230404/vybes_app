<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrganizerEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organizer = $request->user()->organizer;

        if (!$organizer) {
            return response()->json([
                'message' => 'Organizer profile not found.',
            ], 403);
        }

        $events = Event::query()
            ->where('organizer_id', $organizer->id)
            ->with(['venue', 'ticketTypes'])
            ->latest()
            ->get();

        return response()->json([
            'data' => $events,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $organizer = $request->user()->organizer;

        if (!$organizer) {
            return response()->json([
                'message' => 'Organizer profile not found.',
            ], 403);
        }

        $validated = $request->validate([
            'venue_id' => ['nullable', 'exists:venues,id'],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'cover_image' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'status' => ['nullable', 'in:draft,published,cancelled,completed'],
        ]);

        $event = Event::create([
            'organizer_id' => $organizer->id,
            'venue_id' => $validated['venue_id'] ?? null,
            'title' => $validated['title'],
            'slug' => $this->generateUniqueSlug($validated['title']),
            'description' => $validated['description'] ?? null,
            'cover_image' => $validated['cover_image'] ?? null,
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],
            'status' => $validated['status'] ?? 'draft',
        ]);

        return response()->json([
            'message' => 'Event created successfully.',
            'data' => $event->load(['venue', 'ticketTypes']),
        ], 201);
    }

    public function show(Request $request, Event $event): JsonResponse
    {
        $organizer = $request->user()->organizer;

        if (!$organizer || $event->organizer_id !== $organizer->id) {
            return response()->json([
                'message' => 'You are not authorized to access this event.',
            ], 403);
        }

        return response()->json([
            'data' => $event->load(['venue', 'ticketTypes']),
        ]);
    }

    private function generateUniqueSlug(string $title): string
    {
        $baseSlug = Str::slug($title);
        $slug = $baseSlug;
        $counter = 1;

        while (Event::where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}