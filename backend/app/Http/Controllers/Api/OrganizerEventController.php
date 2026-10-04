<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrganizerEventController extends Controller
{
    /**
     * Get organizer events.
     */
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
            ->with([
                'venue',
                'ticketTypes',
            ])
            ->latest()
            ->get();

        return response()->json([
            'data' => $events,
        ]);
    }


    /**
     * Create event.
     */
    public function store(Request $request): JsonResponse
    {
        $organizer = $request->user()->organizer;

        if (!$organizer) {
            return response()->json([
                'message' => 'Organizer profile not found.',
            ], 403);
        }

        $validated = $request->validate([
            'venue_id' => [
                'nullable',
                'exists:venues,id',
            ],

            'title' => [
                'required',
                'string',
                'max:150',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'cover_image' => [
                'nullable',
                'string',
                'max:255',
            ],

            'starts_at' => [
                'required',
                'date',
            ],

            'ends_at' => [
                'required',
                'date',
                'after:starts_at',
            ],

            'status' => [
                'nullable',
                'in:draft,published,cancelled,completed',
            ],
        ]);

        $event = Event::create([
            'organizer_id' => $organizer->id,
            'venue_id' => $validated['venue_id'] ?? null,

            'title' => $validated['title'],

            'slug' => $this->generateUniqueSlug(
                $validated['title']
            ),

            'description' => $validated['description'] ?? null,

            'cover_image' => $validated['cover_image'] ?? null,

            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],

            'status' => $validated['status'] ?? 'draft',
        ]);

        return response()->json([
            'message' => 'Event created successfully.',

            'data' => $event->load([
                'venue',
                'ticketTypes',
            ]),
        ], 201);
    }


    /**
     * Get event detail.
     */
    public function show(
        Request $request,
        Event $event
    ): JsonResponse {
        $organizer = $request->user()->organizer;

        if (
            !$organizer ||
            $event->organizer_id !== $organizer->id
        ) {
            return response()->json([
                'message' =>
                    'You are not authorized to access this event.',
            ], 403);
        }

        return response()->json([
            'data' => $event->load([
                'venue',
                'ticketTypes',
            ]),
        ]);
    }


    /**
     * Update organizer event.
     *
     * ORG-02
     */
    public function update(
        Request $request,
        Event $event
    ): JsonResponse {
        $organizer = $request->user()->organizer;

        /*
        |--------------------------------------------------------------------------
        | Organizer validation
        |--------------------------------------------------------------------------
        */

        if (!$organizer) {
            return response()->json([
                'message' => 'Organizer profile not found.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Ownership validation
        |--------------------------------------------------------------------------
        */

        if ($event->organizer_id !== $organizer->id) {
            return response()->json([
                'message' =>
                    'You are not authorized to update this event.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Prevent editing cancelled/completed event
        |--------------------------------------------------------------------------
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
                    'Cancelled or completed events cannot be edited.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        $validated = $request->validate([
            'venue_id' => [
                'sometimes',
                'nullable',
                'exists:venues,id',
            ],

            'title' => [
                'sometimes',
                'required',
                'string',
                'max:150',
            ],

            'description' => [
                'sometimes',
                'nullable',
                'string',
            ],

            'cover_image' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'starts_at' => [
                'sometimes',
                'required',
                'date',
            ],

            'ends_at' => [
                'sometimes',
                'required',
                'date',
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Validate date range
        |--------------------------------------------------------------------------
        */

        $startsAt = $validated['starts_at']
            ?? $event->starts_at;

        $endsAt = $validated['ends_at']
            ?? $event->ends_at;

        if (
            strtotime((string) $endsAt) <=
            strtotime((string) $startsAt)
        ) {
            return response()->json([
                'message' =>
                    'The event end time must be after the start time.',

                'errors' => [
                    'ends_at' => [
                        'The end time must be after the start time.',
                    ],
                ],
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Prevent changing event into invalid past schedule
        |--------------------------------------------------------------------------
        */

        if (
            array_key_exists('starts_at', $validated) &&
            strtotime((string) $validated['starts_at']) <=
            now()->timestamp
        ) {
            return response()->json([
                'message' =>
                    'Event start time must be in the future.',

                'errors' => [
                    'starts_at' => [
                        'The event start time must be in the future.',
                    ],
                ],
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Update event
        |--------------------------------------------------------------------------
        */

        DB::transaction(function () use (
            $event,
            $validated
        ) {
            /*
            |--------------------------------------------------------------------------
            | Update slug only when title changes
            |--------------------------------------------------------------------------
            */

            if (
                array_key_exists('title', $validated) &&
                $validated['title'] !== $event->title
            ) {
                $event->slug = $this->generateUniqueSlug(
                    $validated['title'],
                    $event->id
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Update allowed fields
            |--------------------------------------------------------------------------
            */

            if (array_key_exists('venue_id', $validated)) {
                $event->venue_id = $validated['venue_id'];
            }

            if (array_key_exists('title', $validated)) {
                $event->title = $validated['title'];
            }

            if (array_key_exists('description', $validated)) {
                $event->description =
                    $validated['description'];
            }

            if (array_key_exists('cover_image', $validated)) {
                $event->cover_image =
                    $validated['cover_image'];
            }

            if (array_key_exists('starts_at', $validated)) {
                $event->starts_at =
                    $validated['starts_at'];
            }

            if (array_key_exists('ends_at', $validated)) {
                $event->ends_at =
                    $validated['ends_at'];
            }

            $event->save();
        });

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'message' => 'Event updated successfully.',

            'data' => $event
                ->fresh()
                ->load([
                    'venue',
                    'ticketTypes',
                ]),
        ]);
    }


    /**
     * Generate unique event slug.
     *
     * $ignoreEventId digunakan ketika UPDATE
     * agar event tidak bentrok dengan slug miliknya sendiri.
     */
    private function generateUniqueSlug(
        string $title,
        ?int $ignoreEventId = null
    ): string {
        $baseSlug = Str::slug($title);

        /*
        |--------------------------------------------------------------------------
        | Fallback apabila title menghasilkan slug kosong
        |--------------------------------------------------------------------------
        */

        if ($baseSlug === '') {
            $baseSlug = 'event';
        }

        $slug = $baseSlug;
        $counter = 1;

        while (
            Event::query()
                ->where('slug', $slug)
                ->when(
                    $ignoreEventId !== null,
                    fn ($query) =>
                        $query->whereKey('!=', $ignoreEventId)
                )
                ->exists()
        ) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}